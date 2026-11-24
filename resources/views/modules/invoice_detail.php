<?php
Auth::requireLogin();
RBAC::require('finance.view');
$pdo = DB::pdo(); $schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user(); $canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','finance'], true);
$id = (int)($_GET['id'] ?? 0); $message=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST' && $canManage && $pdo && $id) {
  $action=$_POST['action']??'';
  try {
    if ($action==='reverse_payment') {
      $pid=(int)$_POST['payment_id']; $reason=trim($_POST['reason']??''); if($reason==='') throw new RuntimeException('Reason required.');
      $st=$pdo->prepare('SELECT * FROM payments WHERE id=? AND school_id=? AND invoice_id=? FOR UPDATE'); $st->execute([$pid,$schoolId,$id]); $payRow=$st->fetch();
      if(!$payRow) throw new RuntimeException('Payment not found.'); if(($payRow['status']??'')==='reversed') throw new RuntimeException('Already reversed.');
      $pdo->prepare('UPDATE payments SET status="reversed",reversed_at=NOW(),reversed_by=?,reversal_reason=? WHERE id=?')->execute([$user['id'],$reason,$pid]);
      $pdo->prepare('UPDATE fee_accounts SET balance=balance+? WHERE student_id=?')->execute([(float)$payRow['amount'],(int)$payRow['student_id']]);
      $sumSt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND school_id=? AND status="posted"'); $sumSt->execute([$id,$schoolId]); $postedSum=(float)$sumSt->fetchColumn();
      $pdo->prepare('UPDATE invoices SET paid_amount=?, balance_amount=GREATEST(total_amount-?,0), status=CASE WHEN ?<=0 THEN "unpaid" WHEN ? < total_amount THEN "partial" ELSE "paid" END WHERE id=? AND school_id=?')->execute([$postedSum,$postedSum,$postedSum,$postedSum,$id,$schoolId]);
      if (DB::hasTable('invoice_instalments')) {
        $pdo->prepare('UPDATE invoice_instalments SET paid_amount=0,status="unpaid" WHERE invoice_id=?')->execute([$id]);
        $rem=$postedSum; $inst=$pdo->prepare('SELECT * FROM invoice_instalments WHERE invoice_id=? ORDER BY instalment_no ASC'); $inst->execute([$id]);
        foreach($inst->fetchAll() as $ins){ $due=max(0,(float)$ins['amount']); $pay=min($due,$rem); $rem=max(0,$rem-$pay); $st=($pay+0.001>=$due && $due>0)?'paid':($pay>0?'partial':'unpaid'); $pdo->prepare('UPDATE invoice_instalments SET paid_amount=?,status=? WHERE id=?')->execute([$pay,$st,$ins['id']]); }
      }
      Audit::log('payment.reverse','payment',$pid,null,['reason'=>$reason,'invoice_id'=>$id]); $message='Payment reversed.';
    }
  } catch(Throwable $e){ $error=$e->getMessage(); }
}
$inv=null;$items=[];$inst=[];$pays=[];
if($pdo && $id){
  $st=$pdo->prepare('SELECT i.*, s.student_no,s.first_name,s.last_name,c.name class_name,t.name term_name FROM invoices i JOIN students s ON s.id=i.student_id LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN terms t ON t.id=i.term_id WHERE i.id=? AND i.school_id=?'); $st->execute([$id,$schoolId]); $inv=$st->fetch();
  if($inv){ $st=$pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id'); $st->execute([$id]); $items=$st->fetchAll();
    if(DB::hasTable('invoice_instalments')){ $st=$pdo->prepare('SELECT * FROM invoice_instalments WHERE invoice_id=? ORDER BY instalment_no'); $st->execute([$id]); $inst=$st->fetchAll(); }
    $st=$pdo->prepare('SELECT p.*, u.name received_by_name FROM payments p LEFT JOIN users u ON u.id=p.received_by WHERE p.invoice_id=? AND p.school_id=? ORDER BY p.paid_at DESC'); $st->execute([$id,$schoolId]); $pays=$st->fetchAll();
  }
}
function money($n){ return 'UGX '.number_format((float)$n,2); }
?>
<div class="card"><div class="section-title"><h2>Invoice Detail</h2><a class="btn secondary" href="<?= e(asset_path('/finance')) ?>">Back</a></div>
<?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
<?php if(!$inv): ?><p class="muted">Invoice not found.</p><?php else: ?>
<div class="grid grid-4" style="margin-top:12px;">
  <div class="empty"><strong>Invoice</strong><div><?= e($inv['invoice_no']) ?></div></div>
  <div class="empty"><strong>Student</strong><div><?= e($inv['student_no'].' '.$inv['first_name'].' '.$inv['last_name']) ?></div></div>
  <div class="empty"><strong>Status</strong><div><span class="badge <?= $inv['status']==='paid'?'ok':'warn' ?>"><?= e($inv['status']) ?></span></div></div>
  <div class="empty"><strong>Balance</strong><div><?= e(money($inv['balance_amount'])) ?></div></div>
</div>
</div>
<div class="grid grid-2">
  <div class="card"><h3>Items</h3><table class="compact"><thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody><?php foreach($items as $it): ?><tr><td><?= e($it['description']) ?></td><td><?= e(money($it['amount'])) ?></td></tr><?php endforeach; if(!$items): ?><tr><td colspan="2" class="muted">No items.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card"><h3>Instalments</h3><table class="compact"><thead><tr><th>#</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead><tbody><?php foreach($inst as $ir): ?><tr><td><?= e($ir['instalment_no']) ?></td><td><?= e($ir['due_date']?:'-') ?></td><td><?= e(money($ir['amount'])) ?></td><td><?= e(money($ir['paid_amount'])) ?></td><td><span class="badge <?= $ir['status']==='paid'?'ok':'warn' ?>"><?= e($ir['status']) ?></span></td></tr><?php endforeach; if(!$inst): ?><tr><td colspan="5" class="muted">No instalments.</td></tr><?php endif; ?></tbody></table></div>
</div>
<div class="card"><h3>Payment Ledger</h3><table class="compact"><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Method</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($pays as $p): ?><tr><td><?= e($p['paid_at']) ?></td><td><?= e($p['receipt_no']) ?></td><td><?= e(money($p['amount'])) ?></td><td><?= e($p['method']) ?></td><td><span class="badge <?= ($p['status']??'')==='reversed'?'danger':'ok' ?>"><?= e($p['status']) ?></span></td><td><?php if($canManage && ($p['status']??'')!=='reversed'): ?><form method="post" style="display:flex;gap:6px;"><?= CSRF::field() ?><input type="hidden" name="action" value="reverse_payment"><input type="hidden" name="payment_id" value="<?= e($p['id']) ?>"><input class="input" name="reason" placeholder="Reason" required style="max-width:160px;"><button class="btn secondary" style="padding:4px 8px;">Reverse</button></form><?php elseif(($p['status']??'')==='reversed'): ?><span class="muted"><?= e($p['reversal_reason']?:'Reversed') ?></span><?php endif; ?></td></tr><?php endforeach; if(!$pays): ?><tr><td colspan="6" class="muted">No payments.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
