<?php
Auth::requireLogin();
RBAC::require('finance.view');
$pdo = DB::pdo(); $schoolId = (int)(Auth::user()['school_id'] ?? 1);
$paymentId = (int)($_GET['payment_id'] ?? 0);
$school = config('school');
$p = null; $inv = null;
if ($pdo && $paymentId) {
    $st=$pdo->prepare('SELECT p.*, s.student_no,s.first_name,s.last_name,s.admission_no,c.name class_name, i.invoice_no,i.total_amount inv_total,i.paid_amount inv_paid,i.balance_amount inv_balance FROM payments p JOIN students s ON s.id=p.student_id LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN invoices i ON i.id=p.invoice_id WHERE p.id=? AND p.school_id=?');
    $st->execute([$paymentId,$schoolId]); $p=$st->fetch();
}
?><!doctype html><html><head><meta charset="utf-8"><title>Receipt</title>
<style>body{font-family:Arial,sans-serif;margin:24px;color:#182230}.hdr{display:flex;gap:16px;align-items:center;border-bottom:2px solid #ddd;padding-bottom:12px;margin-bottom:16px}.hdr img{width:64px;height:64px;object-fit:contain}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f4f7fb;width:35%}.print{margin:16px 0}@media print{.print{display:none}}</style>
</head><body>
<div class="print"><button onclick="window.print()">Print</button></div>
<?php if(!$p): ?><p>Receipt not found.</p><?php else: ?>
<div class="hdr"><img src="<?= e($school['logo']??'') ?>" alt="logo"><div><h2 style="margin:0;"><?= e($school['name']??'School') ?></h2><div><?= e($school['motto']??config('app.tagline')) ?></div><div><strong>Payment Receipt</strong></div></div></div>
<table>
<tr><th>Receipt No</th><td><?= e($p['receipt_no']) ?></td></tr>
<tr><th>Date</th><td><?= e($p['paid_at']) ?></td></tr>
<tr><th>Student</th><td><?= e($p['student_no'].' '.$p['first_name'].' '.$p['last_name']) ?></td></tr>
<tr><th>Class</th><td><?= e($p['class_name']?:'-') ?></td></tr>
<tr><th>Invoice</th><td><?= e($p['invoice_no']?:'Unallocated / advance') ?></td></tr>
<tr><th>Amount Paid</th><td><strong>UGX <?= e(number_format((float)$p['amount'],2)) ?></strong></td></tr>
<tr><th>Method</th><td><?= e($p['method']) ?> <?= e($p['provider']?(' / '.$p['provider']):'') ?></td></tr>
<tr><th>Provider Ref</th><td><?= e($p['provider_reference']?:'-') ?></td></tr>
<tr><th>Status</th><td><?= e($p['status']) ?></td></tr>
<?php if($p['invoice_no']): ?><tr><th>Invoice Balance</th><td>UGX <?= e(number_format((float)$p['inv_balance'],2)) ?></td></tr><?php endif; ?>
<tr><th>Notes</th><td><?= e($p['notes']?:'-') ?></td></tr>
</table>
<p style="margin-top:24px;color:#666;font-size:.9rem;">This is a system-generated receipt from <?= e($school['name']??'SchoolGate') ?>.</p>
<?php endif; ?></body></html>
