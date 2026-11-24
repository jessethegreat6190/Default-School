<?php
RBAC::require('finance.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','finance'], true);

function money($n){ return 'UGX ' . number_format((float)$n, 2); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_structure') {
            $stmt=$pdo->prepare('INSERT INTO fee_structures (school_id,class_id,term_id,name,amount,currency,is_active) VALUES (?,?,?,?,?,?,1)');
            $stmt->execute([$schoolId,$_POST['class_id']?:null,$_POST['term_id']?:null,trim($_POST['name']), (float)$_POST['amount'], $_POST['currency']?:'UGX']);
            Audit::log('fee_structure.create','fee_structure',$pdo->lastInsertId(),null,$_POST); $message='Fee structure added.';
        }
        if ($action === 'create_invoice') {
            $studentId=(int)$_POST['student_id']; $baseAmount=(float)$_POST['total_amount'];
            $prev = (float)($_POST['previous_balance'] ?? 0);
            $carry = (float)($_POST['carried_forward'] ?? 0);
            $instalments = max(1,(int)($_POST['instalments'] ?? 1));
            $amount = $baseAmount + $prev + $carry;
            $invNo = trim($_POST['invoice_no'] ?: ('INV-'.date('Ymd').'-'.random_int(1000,9999)));
            $stmt=$pdo->prepare('INSERT INTO invoices (school_id,student_id,fee_structure_id,invoice_no,term_id,due_date,total_amount,paid_amount,balance_amount,status,notes,created_by,instalments,previous_balance,carried_forward) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,$studentId,$_POST['fee_structure_id']?:null,$invNo,$_POST['term_id']?:null,$_POST['due_date']?:null,$amount,0,$amount,'unpaid',trim($_POST['notes']?:null),$user['id'],$instalments,$prev,$carry]);
            $invId=(int)$pdo->lastInsertId();
            $desc = trim($_POST['description'] ?: 'School fees');
            $pdo->prepare('INSERT INTO invoice_items (invoice_id,description,amount) VALUES (?,?,?)')->execute([$invId,$desc,$baseAmount]);
            if ($prev > 0) $pdo->prepare('INSERT INTO invoice_items (invoice_id,description,amount) VALUES (?,?,?)')->execute([$invId,'Previous balance',$prev]);
            if ($carry > 0) $pdo->prepare('INSERT INTO invoice_items (invoice_id,description,amount) VALUES (?,?,?)')->execute([$invId,'Carried forward',$carry]);
            if (DB::hasTable('invoice_instalments')) {
                $per = round($amount / $instalments, 2); $remaining = $amount;
                for ($n=1; $n<=$instalments; $n++) {
                    $amt = ($n === $instalments) ? $remaining : $per;
                    $remaining = round($remaining - $amt, 2);
                    $due = $_POST['due_date'] ?: null;
                    if ($due) { $d = new DateTime($due); if ($n > 1) $d->modify('+' . (($n-1)) . ' month'); $due = $d->format('Y-m-d'); }
                    $pdo->prepare('INSERT INTO invoice_instalments (invoice_id,instalment_no,due_date,amount,paid_amount,status) VALUES (?,?,?,?,0,"unpaid")')->execute([$invId,$n,$due,$amt]);
                }
            }
            $pdo->prepare('UPDATE fee_accounts SET balance = balance + ? WHERE student_id=?')->execute([$amount,$studentId]);
            Audit::log('invoice.create','invoice',$invId,null,$_POST); $message="Invoice $invNo created (total ".number_format($amount,2).", instalments $instalments).";
        }
        if ($action === 'record_payment') {
            $studentId=(int)$_POST['student_id']; $amount=(float)$_POST['amount']; $invoiceId=$_POST['invoice_id']!==''?(int)$_POST['invoice_id']:null;
            $receipt = trim($_POST['receipt_no'] ?: ('RCT-'.date('Ymd').'-'.random_int(1000,9999)));
            $paidAt = $_POST['paid_at'] ? str_replace('T',' ',$_POST['paid_at']).':00' : date('Y-m-d H:i:s');
            $stmt=$pdo->prepare('INSERT INTO payments (school_id,student_id,invoice_id,receipt_no,amount,currency,method,provider,provider_reference,status,paid_at,received_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,$studentId,$invoiceId,$receipt,$amount,$_POST['currency']?:'UGX',$_POST['method']?:'cash',trim($_POST['provider']?:null),trim($_POST['provider_reference']?:null),'posted',$paidAt,$user['id'],trim($_POST['notes']?:null)]);
            $pid=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE fee_accounts SET balance = GREATEST(balance - ?, 0) WHERE student_id=?')->execute([$amount,$studentId]);
            if ($invoiceId) {
                $pdo->prepare('UPDATE invoices SET paid_amount = paid_amount + ?, balance_amount = GREATEST(total_amount - (paid_amount + ?),0), status = CASE WHEN total_amount <= (paid_amount + ?) THEN "paid" WHEN (paid_amount + ?) > 0 THEN "partial" ELSE "unpaid" END WHERE id=? AND school_id=?')
                    ->execute([$amount,$amount,$amount,$amount,$invoiceId,$schoolId]);
                if (DB::hasTable('invoice_instalments')) {
                    $rem=$amount; $inst=$pdo->prepare('SELECT * FROM invoice_instalments WHERE invoice_id=? AND status IN ("unpaid","partial") ORDER BY instalment_no ASC FOR UPDATE'); $inst->execute([$invoiceId]);
                    foreach($inst->fetchAll() as $ins){ if($rem<=0) break; $due=max(0,(float)$ins['amount']-(float)$ins['paid_amount']); $pay=min($due,$rem); $rem-=$pay; $newPaid=(float)$ins['paid_amount']+$pay; $st=($newPaid+0.001>= (float)$ins['amount'])?'paid':'partial'; $pdo->prepare('UPDATE invoice_instalments SET paid_amount=?, status=? WHERE id=?')->execute([$newPaid,$st,$ins['id']]); }
                }
            }
            Audit::log('payment.record','payment',$pid,null,$_POST);
            $ssn=$pdo->prepare('SELECT first_name,last_name,student_no FROM students WHERE id=?'); $ssn->execute([$studentId]); $sr=$ssn->fetch();
            $sname = $sr ? ($sr['student_no'].' '.$sr['first_name'].' '.$sr['last_name']) : 'Student';
            $nmsg = "Payment received for $sname: UGX " . number_format($amount,2) . ". Receipt: $receipt. Thank you.";
            $sent = Notifier::notifyStudentGuardians($schoolId,$studentId,'fee','Payment received',$nmsg,'payment',$pid,$user['id']);
            $message="Payment recorded. Receipt: $receipt. Notifications: $sent.";
        }
        if ($action === 'reverse_payment') {
            $pid=(int)$_POST['payment_id']; $reason=trim($_POST['reason'] ?? ''); if($reason==='') throw new RuntimeException('Reversal reason required.');
            $st=$pdo->prepare('SELECT * FROM payments WHERE id=? AND school_id=? FOR UPDATE'); $st->execute([$pid,$schoolId]); $payRow=$st->fetch();
            if(!$payRow) throw new RuntimeException('Payment not found.'); if(($payRow['status']??'')==='reversed') throw new RuntimeException('Payment already reversed.');
            $pdo->prepare('UPDATE payments SET status="reversed", reversed_at=NOW(), reversed_by=?, reversal_reason=? WHERE id=?')->execute([$user['id'],$reason,$pid]);
            $pdo->prepare('UPDATE fee_accounts SET balance = balance + ? WHERE student_id=?')->execute([(float)$payRow['amount'],(int)$payRow['student_id']]);
            if (!empty($payRow['invoice_id'])) {
                $invId=(int)$payRow['invoice_id'];
                $sumSt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND school_id=? AND status="posted"'); $sumSt->execute([$invId,$schoolId]); $postedSum=(float)$sumSt->fetchColumn();
                $pdo->prepare('UPDATE invoices SET paid_amount=?, balance_amount=GREATEST(total_amount-?,0), status=CASE WHEN ?<=0 THEN "unpaid" WHEN ? < total_amount THEN "partial" ELSE "paid" END WHERE id=? AND school_id=?')->execute([$postedSum,$postedSum,$postedSum,$postedSum,$invId,$schoolId]);
                if (DB::hasTable('invoice_instalments')) {
                    $pdo->prepare('UPDATE invoice_instalments SET paid_amount=0, status="unpaid" WHERE invoice_id=?')->execute([$invId]);
                    $rem=$postedSum; $inst=$pdo->prepare('SELECT * FROM invoice_instalments WHERE invoice_id=? ORDER BY instalment_no ASC'); $inst->execute([$invId]);
                    foreach($inst->fetchAll() as $ins){ $due=max(0,(float)$ins['amount']); $pay=min($due,$rem); $rem=max(0,$rem-$pay); $st=($pay+0.001>=$due && $due>0)?'paid':($pay>0?'partial':'unpaid'); $pdo->prepare('UPDATE invoice_instalments SET paid_amount=?, status=? WHERE id=?')->execute([$pay,$st,$ins['id']]); }
                }
            }
            Audit::log('payment.reverse','payment',$pid,null,['reason'=>$reason]); $message='Payment reversed and balances recalculated.';
        }
        if ($action === 'add_expense') {
            $stmt=$pdo->prepare('INSERT INTO expenses (school_id,category,description,amount,currency,expense_date,paid_to,reference,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,trim($_POST['category']),trim($_POST['description']),(float)$_POST['amount'],$_POST['currency']?:'UGX',$_POST['expense_date'],trim($_POST['paid_to']?:null),trim($_POST['reference']?:null),$user['id']]);
            Audit::log('expense.create','expense',$pdo->lastInsertId(),null,$_POST); $message='Expense recorded.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$students=$structures=$terms=$invoices=$payments=$expenses=[]; $summary=['invoiced'=>0,'paid'=>0,'balance'=>0,'expenses'=>0];
if ($pdo) {
    $st=$pdo->prepare('SELECT id,student_no,first_name,last_name FROM students WHERE school_id=? AND status="active" ORDER BY first_name,last_name'); $st->execute([$schoolId]); $students=$st->fetchAll();
    $terms=$pdo->query('SELECT t.*, y.name year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY t.id DESC')->fetchAll();
    $structures=$pdo->prepare('SELECT fs.*, c.name class_name, t.name term_name FROM fee_structures fs LEFT JOIN classes c ON c.id=fs.class_id LEFT JOIN terms t ON t.id=fs.term_id WHERE fs.school_id=? ORDER BY fs.id DESC'); $structures->execute([$schoolId]); $structures=$structures->fetchAll();
    $invoices=$pdo->prepare('SELECT i.*, s.student_no,s.first_name,s.last_name FROM invoices i JOIN students s ON s.id=i.student_id WHERE i.school_id=? ORDER BY i.created_at DESC LIMIT 50'); $invoices->execute([$schoolId]); $invoices=$invoices->fetchAll();
    $payments=$pdo->prepare('SELECT p.*, s.student_no,s.first_name,s.last_name,i.invoice_no FROM payments p JOIN students s ON s.id=p.student_id LEFT JOIN invoices i ON i.id=p.invoice_id WHERE p.school_id=? ORDER BY p.paid_at DESC LIMIT 50'); $payments->execute([$schoolId]); $payments=$payments->fetchAll();
    $expenses=$pdo->prepare('SELECT * FROM expenses WHERE school_id=? ORDER BY expense_date DESC, id DESC LIMIT 50'); $expenses->execute([$schoolId]); $expenses=$expenses->fetchAll();
    foreach($invoices as $i){ $summary['invoiced']+=(float)$i['total_amount']; $summary['paid']+=(float)$i['paid_amount']; $summary['balance']+=(float)$i['balance_amount']; }
    foreach($expenses as $e){ $summary['expenses']+=(float)$e['amount']; }
}
?>
<div class="card">
  <div class="section-title"><h2>Fees & Finance</h2><span class="badge warn">Phase 5</span></div>
  <p class="muted">Fee structures, invoices, receipts/payments, expenses. SchoolPay/mobile money reconciliation hooks come next.</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-4">
  <div class="card stat"><div class="label">Invoiced</div><div class="value" style="font-size:1.4rem;"><?= e(money($summary['invoiced'])) ?></div></div>
  <div class="card stat"><div class="label">Collected</div><div class="value" style="font-size:1.4rem;"><?= e(money($summary['paid'])) ?></div></div>
  <div class="card stat"><div class="label">Outstanding</div><div class="value" style="font-size:1.4rem;"><?= e(money($summary['balance'])) ?></div></div>
  <div class="card stat"><div class="label">Expenses</div><div class="value" style="font-size:1.4rem;"><?= e(money($summary['expenses'])) ?></div></div>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Create Invoice</h3>
    <?php if($canManage): ?><form method="post" class="grid"><?= CSRF::field() ?>
      <input type="hidden" name="action" value="create_invoice">
      <label>Student<select class="input" name="student_id" required><?php foreach($students as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['student_no'].' - '.$s['first_name'].' '.$s['last_name']) ?></option><?php endforeach; ?></select></label>
      <label>Fee structure<select class="input" name="fee_structure_id"><option value="">Manual</option><?php foreach($structures as $fs): ?><option value="<?= e($fs['id']) ?>" data-amount="<?= e($fs['amount']) ?>" data-name="<?= e($fs['name']) ?>"><?= e($fs['name'].' - '.money($fs['amount'])) ?></option><?php endforeach; ?></select></label>
      <div class="grid grid-2"><label>Invoice No<input class="input" name="invoice_no" placeholder="auto"></label><label>Term<select class="input" name="term_id"><option value="">Optional</option><?php foreach($terms as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['year_name'].' - '.$t['name']) ?></option><?php endforeach; ?></select></label></div>
      <label>Description<input class="input" name="description" value="School fees"></label>
      <div class="grid grid-3"><label>Base (UGX)<input class="input" name="total_amount" type="number" step="0.01" required></label><label>Previous balance<input class="input" name="previous_balance" type="number" step="0.01" value="0"></label><label>Carried forward<input class="input" name="carried_forward" type="number" step="0.01" value="0"></label></div>
      <div class="grid grid-2"><label>Instalments<input class="input" name="instalments" type="number" min="1" value="1"></label><label>Due date<input class="input" type="date" name="due_date"></label></div>
      <label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
      <button class="btn" type="submit">Create Invoice</button>
    </form><?php endif; ?>
  </div>
  <div class="card">
    <h3>Record Payment / Receipt</h3>
    <?php if($canManage): ?><form method="post" class="grid"><?= CSRF::field() ?>
      <input type="hidden" name="action" value="record_payment">
      <label>Student<select class="input" name="student_id" required><?php foreach($students as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['student_no'].' - '.$s['first_name'].' '.$s['last_name']) ?></option><?php endforeach; ?></select></label>
      <label>Invoice<select class="input" name="invoice_id"><option value="">Unallocated / advance</option><?php foreach($invoices as $i): if($i['status']!=='paid'): ?><option value="<?= e($i['id']) ?>"><?= e($i['invoice_no'].' - '.$i['first_name'].' '.$i['last_name'].' bal '.money($i['balance_amount'])) ?></option><?php endif; endforeach; ?></select></label>
      <div class="grid grid-2"><label>Receipt No<input class="input" name="receipt_no" placeholder="auto"></label><label>Amount<input class="input" name="amount" type="number" step="0.01" required></label></div>
      <div class="grid grid-2"><label>Method<select class="input" name="method"><option>cash</option><option>bank</option><option>mobile_money</option><option>schoolpay</option><option>cheque</option></select></label><label>Provider<input class="input" name="provider" placeholder="MTN/Airtel/SchoolPay"></label></div>
      <div class="grid grid-2"><label>Provider Ref<input class="input" name="provider_reference"></label><label>Paid at<input class="input" type="datetime-local" name="paid_at" value="<?= e(date('Y-m-d\TH:i')) ?>"></label></div>
      <label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
      <button class="btn" type="submit">Record Payment</button>
    </form><?php endif; ?>
  </div>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Recent Invoices</h3>
    <table class="compact"><thead><tr><th>Invoice</th><th>Student</th><th>Total</th><th>Balance</th><th>Status</th></tr></thead><tbody>
      <?php foreach($invoices as $i): ?><tr><td><?= e($i['invoice_no']) ?></td><td><?= e($i['student_no'].' '.$i['first_name']) ?></td><td><?= e(money($i['total_amount'])) ?></td><td><?= e(money($i['balance_amount'])) ?></td><td><span class="badge <?= $i['status']==='paid'?'ok':'warn' ?>"><?= e($i['status']) ?></span></td></tr><?php endforeach; if(!$invoices): ?><tr><td colspan="5" class="muted">No invoices yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <div class="card">
    <h3>Recent Payments</h3>
    <table class="compact"><thead><tr><th>Receipt</th><th>Student</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach($payments as $pmt): ?><tr><td><?= e($pmt['receipt_no']) ?></td><td><?= e($pmt['student_no'].' '.$pmt['first_name']) ?></td><td><?= e(money($pmt['amount'])) ?></td><td><?= e($pmt['method']) ?></td><td><span class="badge <?= ($pmt['status']??'')==='reversed'?'danger':'ok' ?>"><?= e($pmt['status']??'posted') ?></span></td><td style="white-space:nowrap;"><a class="btn secondary" style="padding:2px 8px;font-size:.75rem;" href="<?= e(asset_path('/receipt/print?payment_id='.$pmt['id'])) ?>" target="_blank">Print</a><?php if($canManage && ($pmt['status']??'')!=='reversed'): ?><form method="post" style="display:inline" onsubmit="return confirm('Reverse this payment?')"><?= CSRF::field() ?><input type="hidden" name="action" value="reverse_payment"><input type="hidden" name="payment_id" value="<?= e($pmt['id']) ?>"><input type="hidden" name="reason" value="Reversed by admin"><button class="btn secondary" style="padding:2px 8px;font-size:.75rem;">Reverse</button></form><?php endif; ?></td></tr><?php endforeach; if(!$payments): ?><tr><td colspan="6" class="muted">No payments yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<div class="card">
  <div class="section-title"><h3>Invoice Instalments</h3><span class="muted">Recent invoice schedules</span></div>
  <table class="compact"><thead><tr><th>Invoice</th><th>Instalment</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead><tbody>
    <?php $instRows=[]; if($pdo && DB::hasTable('invoice_instalments')){ $st=$pdo->prepare('SELECT ii.*, i.invoice_no, s.student_no,s.first_name,s.last_name FROM invoice_instalments ii JOIN invoices i ON i.id=ii.invoice_id JOIN students s ON s.id=i.student_id WHERE i.school_id=? ORDER BY ii.due_date IS NULL, ii.due_date ASC, ii.id DESC LIMIT 30'); $st->execute([$schoolId]); $instRows=$st->fetchAll(); }
    foreach($instRows as $ir): ?><tr><td><?= e($ir['invoice_no'].' - '.$ir['student_no']) ?></td><td>#<?= e($ir['instalment_no']) ?></td><td><?= e($ir['due_date']?:'-') ?></td><td><?= e(money($ir['amount'])) ?></td><td><?= e(money($ir['paid_amount'])) ?></td><td><span class="badge <?= $ir['status']==='paid'?'ok':'warn' ?>"><?= e($ir['status']) ?></span></td></tr><?php endforeach; if(!$instRows): ?><tr><td colspan="6" class="muted">No instalments yet.</td></tr><?php endif; ?>
  </tbody></table>
</div>
<div class="card">
  <h3>Expenses</h3>
  <?php if($canManage): ?><form method="post" class="grid grid-4" style="margin-bottom:12px;"><?= CSRF::field() ?><input type="hidden" name="action" value="add_expense">
    <label>Category<input class="input" name="category" required></label><label>Description<input class="input" name="description" required></label><label>Amount<input class="input" name="amount" type="number" step="0.01" required></label><label>Date<input class="input" type="date" name="expense_date" value="<?= e(date('Y-m-d')) ?>" required></label>
    <label>Paid to<input class="input" name="paid_to"></label><label>Reference<input class="input" name="reference"></label><div style="display:flex;align-items:end;"><button class="btn" type="submit">Add Expense</button></div>
  </form><?php endif; ?>
  <table class="compact"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr></thead><tbody>
    <?php foreach($expenses as $ex): ?><tr><td><?= e($ex['expense_date']) ?></td><td><?= e($ex['category']) ?></td><td><?= e($ex['description']) ?></td><td><?= e(money($ex['amount'])) ?></td></tr><?php endforeach; if(!$expenses): ?><tr><td colspan="4" class="muted">No expenses yet.</td></tr><?php endif; ?>
  </tbody></table>
</div>













