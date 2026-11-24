<?php
RBAC::require('finance.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','finance'], true);

function parse_csv_rows(string $path): array {
    $fh = fopen($path, 'r'); if (!$fh) return [];
    $header = fgetcsv($fh); if (!$header) { fclose($fh); return []; }
    $map = []; foreach ($header as $i=>$h) { $map[strtolower(trim((string)$h))] = $i; }
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        $get = function($keys) use ($map,$r){ foreach((array)$keys as $k){ $k=strtolower($k); if(isset($map[$k]) && isset($r[$map[$k]]) && trim((string)$r[$map[$k]])!=='') return trim((string)$r[$map[$k]]); } return null; };
        $rows[] = [
            'provider_reference' => $get(['provider_reference','reference','tx_ref','transaction_id','transid','receipt']),
            'payer_name' => $get(['payer_name','payer','name','student_name']),
            'student_no' => $get(['student_no','student_id','admission_no','reg_no']),
            'invoice_no' => $get(['invoice_no','invoice','inv_no']),
            'amount' => (float) str_replace([',',' '],'',(string)($get(['amount','paid','total','value']) ?? 0)),
            'currency' => $get(['currency']) ?: 'UGX',
            'paid_at' => $get(['paid_at','date','transaction_date','time']),
        ];
    }
    fclose($fh); return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'import_statement') {
            $provider = trim($_POST['provider'] ?? 'mobile_money');
            if (!isset($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload a CSV statement file.');
            $rows = parse_csv_rows($_FILES['statement']['tmp_name']);
            if (!$rows) throw new RuntimeException('No rows found. CSV must have headers like provider_reference,student_no/invoice_no,amount,paid_at.');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO payment_statement_imports (school_id,provider,source_name,imported_by,total_rows,notes) VALUES (?,?,?,?,?,?)')
                ->execute([$schoolId,$provider,$_FILES['statement']['name'],$user['id'],count($rows),trim($_POST['notes']?:null)]);
            $importId = (int)$pdo->lastInsertId();
            $matched=$unmatched=$duplicates=0;
            $ins = $pdo->prepare('INSERT INTO payment_statement_rows (import_id,school_id,provider,provider_reference,payer_name,student_no,invoice_no,amount,currency,paid_at,raw_data,match_status,matched_payment_id,matched_invoice_id,matched_student_id,review_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $status='unmatched'; $paymentId=$invoiceId=$studentId=null; $note=null;
                $paidAt = $r['paid_at'] ? date('Y-m-d H:i:s', strtotime($r['paid_at'])) : null;
                if ($r['provider_reference']) {
                    $dup = $pdo->prepare('SELECT id FROM payments WHERE school_id=? AND provider_reference=? LIMIT 1');
                    $dup->execute([$schoolId,$r['provider_reference']]);
                    $existingPid = $dup->fetchColumn();
                    if ($existingPid) { $status='duplicate'; $paymentId=(int)$existingPid; $note='Provider reference already posted.'; $duplicates++; }
                }
                if ($status !== 'duplicate') {
                    $invoice = null;
                    if ($r['invoice_no']) {
                        $st=$pdo->prepare('SELECT * FROM invoices WHERE school_id=? AND invoice_no=? LIMIT 1'); $st->execute([$schoolId,$r['invoice_no']]); $invoice=$st->fetch();
                    }
                    $student = null;
                    if (!$invoice && $r['student_no']) {
                        $st=$pdo->prepare('SELECT * FROM students WHERE school_id=? AND (student_no=? OR admission_no=?) LIMIT 1'); $st->execute([$schoolId,$r['student_no'],$r['student_no']]); $student=$st->fetch();
                    }
                    if ($invoice) {
                        $invoiceId=(int)$invoice['id']; $studentId=(int)$invoice['student_id'];
                    } elseif ($student) {
                        $studentId=(int)$student['id'];
                        $st=$pdo->prepare('SELECT * FROM invoices WHERE school_id=? AND student_id=? AND status IN ("unpaid","partial") ORDER BY due_date IS NULL, due_date ASC, id ASC LIMIT 1');
                        $st->execute([$schoolId,$studentId]); $invoice=$st->fetch();
                        if ($invoice) $invoiceId=(int)$invoice['id'];
                    }
                    if ($studentId && $r['amount'] > 0) {
                        $receipt='RCT-STMT-'.date('Ymd').'-'.random_int(1000,9999);
                        $p=$pdo->prepare('INSERT INTO payments (school_id,student_id,invoice_id,receipt_no,amount,currency,method,provider,provider_reference,status,paid_at,received_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                        $p->execute([$schoolId,$studentId,$invoiceId,$receipt,$r['amount'],$r['currency']?:'UGX','mobile_money',$provider,$r['provider_reference'],'posted',$paidAt ?: date('Y-m-d H:i:s'),$user['id'],'Imported from statement '.$importId]);
                        $paymentId=(int)$pdo->lastInsertId();
                        $pdo->prepare('UPDATE fee_accounts SET balance = GREATEST(balance - ?,0) WHERE student_id=?')->execute([$r['amount'],$studentId]);
                        if ($invoiceId) {
                            $pdo->prepare('UPDATE invoices SET paid_amount=paid_amount+?, balance_amount=GREATEST(total_amount-(paid_amount+?),0), status=CASE WHEN total_amount <= (paid_amount+?) THEN "paid" WHEN (paid_amount+?)>0 THEN "partial" ELSE "unpaid" END WHERE id=? AND school_id=?')
                                ->execute([$r['amount'],$r['amount'],$r['amount'],$r['amount'],$invoiceId,$schoolId]);
                        }
                        $status = $invoiceId ? 'matched' : 'posted_unallocated';
                        $note = $invoiceId ? 'Matched and posted to invoice.' : 'Posted to student account; no open invoice matched.';
                        $matched++;
                    } else {
                        $status='unmatched'; $note='Could not match student/invoice. Review manually.'; $unmatched++;
                    }
                }
                $ins->execute([$importId,$schoolId,$provider,$r['provider_reference'],$r['payer_name'],$r['student_no'],$r['invoice_no'],$r['amount'],$r['currency']?:'UGX',$paidAt,json_encode($r),$status,$paymentId,$invoiceId,$studentId,$note]);
            }
            $pdo->prepare('UPDATE payment_statement_imports SET matched_rows=?, unmatched_rows=?, duplicate_rows=? WHERE id=?')->execute([$matched,$unmatched,$duplicates,$importId]);
            $pdo->commit();
            Audit::log('statement.import','payment_statement_import',$importId,null,['provider'=>$provider,'matched'=>$matched,'unmatched'=>$unmatched,'duplicates'=>$duplicates]);
            $message = "Statement imported. Matched/posted: $matched, unmatched: $unmatched, duplicates: $duplicates.";
        }
    } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
}

$imports=[]; $rows=[];
if ($pdo && DB::hasTable('payment_statement_imports')) {
    $st=$pdo->prepare('SELECT i.*, u.name imported_by_name FROM payment_statement_imports i LEFT JOIN users u ON u.id=i.imported_by WHERE i.school_id=? ORDER BY i.id DESC LIMIT 20'); $st->execute([$schoolId]); $imports=$st->fetchAll();
    $st=$pdo->prepare('SELECT r.*, s.student_no, s.first_name, s.last_name, i.invoice_no FROM payment_statement_rows r LEFT JOIN students s ON s.id=r.matched_student_id LEFT JOIN invoices i ON i.id=r.matched_invoice_id WHERE r.school_id=? ORDER BY r.id DESC LIMIT 100'); $st->execute([$schoolId]); $rows=$st->fetchAll();
}
?>
<div class="card">
  <div class="section-title"><h2>Payment Reconciliation</h2><span class="badge warn">SchoolPay / Mobile Money</span></div>
  <p class="muted">Import provider statements (CSV), auto-match by provider reference/invoice/student, post to payments, flag duplicates and unmatched rows for review.</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Import Statement (CSV)</h3>
    <?php if($canManage): ?>
    <form method="post"><?= CSRF::field() ?> enctype="multipart/form-data" class="grid">
      <input type="hidden" name="action" value="import_statement">
      <label>Provider<select class="input" name="provider"><option value="schoolpay">SchoolPay</option><option value="mtn_momo">MTN MoMo</option><option value="airtel_money">Airtel Money</option><option value="bank">Bank</option><option value="other">Other</option></select></label>
      <label>CSV file<input class="input" type="file" name="statement" accept=".csv,text/csv" required></label>
      <label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
      <button class="btn" type="submit">Import & Reconcile</button>
    </form>
    <?php endif; ?>
    <div class="empty" style="margin-top:12px;">
      <strong>Expected CSV headers</strong>
      <div class="muted" style="font-size:.85rem;margin-top:6px;">`provider_reference, student_no, invoice_no, amount, paid_at, payer_name, currency` (names flexible; reference/invoice/student_no used for matching).</div>
    </div>
  </div>
  <div class="card">
    <h3>Recent Imports</h3>
    <table class="compact"><thead><tr><th>Date</th><th>Provider</th><th>Total</th><th>Matched</th><th>Unmatched</th><th>Dup</th></tr></thead><tbody>
      <?php foreach($imports as $i): ?><tr><td><?= e($i['imported_at']) ?></td><td><?= e($i['provider']) ?></td><td><?= e($i['total_rows']) ?></td><td><span class="badge ok"><?= e($i['matched_rows']) ?></span></td><td><span class="badge warn"><?= e($i['unmatched_rows']) ?></span></td><td><span class="badge"><?= e($i['duplicate_rows']) ?></span></td></tr><?php endforeach; if(!$imports): ?><tr><td colspan="6" class="muted">No imports yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<div class="card">
  <h3>Statement Rows / Review Queue</h3>
  <table class="compact"><thead><tr><th>Provider Ref</th><th>Student</th><th>Invoice</th><th>Amount</th><th>Status</th><th>Note</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?><tr>
      <td><?= e($r['provider_reference'] ?: '-') ?></td>
      <td><?= e($r['student_no'] ? ($r['student_no'].' '.$r['first_name']) : ($r['payer_name'] ?: '-')) ?></td>
      <td><?= e($r['invoice_no'] ?: ($r['invoice_no'] ?? '-')) ?></td>
      <td><?= e($r['currency'].' '.number_format((float)$r['amount'],2)) ?></td>
      <td><span class="badge <?= in_array($r['match_status'],['matched','posted_unallocated'])?'ok':($r['match_status']==='duplicate'?'':'warn') ?>"><?= e($r['match_status']) ?></span></td>
      <td><?= e($r['review_note'] ?: '') ?></td>
    </tr><?php endforeach; if(!$rows): ?><tr><td colspan="6" class="muted">No statement rows yet.</td></tr><?php endif; ?>
  </tbody></table>
</div>



