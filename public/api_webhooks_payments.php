<?php
// Public webhook endpoint: /api/webhooks/payments?provider=schoolpay&token=...
require_once __DIR__ . '/../app/Core/helpers.php';
require_once __DIR__ . '/../app/Core/DB.php';
require_once __DIR__ . '/../app/Core/Settings.php';
require_once __DIR__ . '/../app/Core/Audit.php';
Auth::start();

header('Content-Type: application/json');
$provider = strtolower(trim((string)($_GET['provider'] ?? 'unknown')));
$token = (string)($_GET['token'] ?? $_SERVER['HTTP_X_SCHOOLGATE_TOKEN'] ?? '');
$cfg = Settings::get('payments', []);
$expected = (string)($cfg['webhook_token'] ?? '');
$sig = (string)($_SERVER['HTTP_X_SCHOOLGATE_SIGNATURE'] ?? '');
$raw = file_get_contents('php://input');
$authorized = ($expected !== '' && hash_equals($expected, $token));
if (!$authorized && $expected !== '' && $sig !== '') {
    $authorized = hash_equals(hash_hmac('sha256', $raw ?: '', $expected), $sig);
}
if (!$authorized) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$payload = json_decode($raw ?: '[]', true); if (!is_array($payload)) $payload = ['raw'=>$raw];
$headers = function_exists('getallheaders') ? getallheaders() : [];


$schoolId = (int)($cfg['school_id'] ?? 1);
$pdo = DB::pdo();
if (!$pdo) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); exit; }

$ref = (string)($payload['provider_reference'] ?? $payload['reference'] ?? $payload['tx_ref'] ?? $payload['transaction_id'] ?? '');
$amount = (float)($payload['amount'] ?? $payload['paid'] ?? 0);
$studentNo = (string)($payload['student_no'] ?? $payload['admission_no'] ?? '');
$invoiceNo = (string)($payload['invoice_no'] ?? '');
$currency = (string)($payload['currency'] ?? 'UGX');
$paidAt = date('Y-m-d H:i:s', strtotime((string)($payload['paid_at'] ?? $payload['date'] ?? 'now')));
$status='received'; $error=null; $paymentId=null; $studentId=null; $invoiceId=null;

try {
    $st=$pdo->prepare('INSERT INTO webhook_events (school_id,provider,event_type,external_ref,payload_json,headers_json,status) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$schoolId,$provider,(string)($payload['event']??'payment'),$ref,json_encode($payload),json_encode($headers),'received']);
    $eventId = (int)$pdo->lastInsertId();

    if ($ref) {
        $dup=$pdo->prepare('SELECT id FROM payments WHERE provider_reference=? AND school_id=? LIMIT 1'); $dup->execute([$ref,$schoolId]); $paymentId=$dup->fetchColumn();
        if ($paymentId) { $status='duplicate'; }
    }
    if ($status!=='duplicate') {
        if ($invoiceNo) { $s=$pdo->prepare('SELECT * FROM invoices WHERE school_id=? AND invoice_no=? LIMIT 1'); $s->execute([$schoolId,$invoiceNo]); $inv=$s->fetch(); if($inv){$invoiceId=(int)$inv['id']; $studentId=(int)$inv['student_id'];} }
        if (!$studentId && $studentNo) { $s=$pdo->prepare('SELECT * FROM students WHERE school_id=? AND (student_no=? OR admission_no=?) LIMIT 1'); $s->execute([$schoolId,$studentNo,$studentNo]); $stu=$s->fetch(); if($stu){$studentId=(int)$stu['id'];} }
        if (!$invoiceId && $studentId) { $s=$pdo->prepare('SELECT * FROM invoices WHERE school_id=? AND student_id=? AND status IN ("unpaid","partial") ORDER BY due_date IS NULL,due_date,id LIMIT 1'); $s->execute([$schoolId,$studentId]); $inv=$s->fetch(); if($inv){$invoiceId=(int)$inv['id'];} }
        if ($studentId && $amount>0) {
            $receipt='RCT-WH-'.date('Ymd').'-'.random_int(1000,9999);
            $p=$pdo->prepare('INSERT INTO payments (school_id,student_id,invoice_id,receipt_no,amount,currency,method,provider,provider_reference,status,paid_at,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $p->execute([$schoolId,$studentId,$invoiceId,$receipt,$amount,$currency,'mobile_money',$provider,$ref?:null,'posted',$paidAt,'Webhook import']);
            $paymentId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE fee_accounts SET balance=GREATEST(balance-?,0) WHERE student_id=?')->execute([$amount,$studentId]);
            if ($invoiceId) { $pdo->prepare('UPDATE invoices SET paid_amount=paid_amount+?, balance_amount=GREATEST(total_amount-(paid_amount+?),0), status=CASE WHEN total_amount<=(paid_amount+?) THEN "paid" WHEN (paid_amount+?)>0 THEN "partial" ELSE "unpaid" END WHERE id=? AND school_id=?')->execute([$amount,$amount,$amount,$amount,$invoiceId,$schoolId]); }
            $status=$invoiceId?'posted':'posted_unallocated';
        } else { $status='unmatched'; $error='Could not match student/invoice or amount invalid.'; }
    }
    $pdo->prepare('UPDATE webhook_events SET status=?, error_message=?, processed_at=NOW() WHERE id=?')->execute([$status,$error,$eventId]);
    echo json_encode(['ok'=>true,'status'=>$status,'payment_id'=>$paymentId,'student_id'=>$studentId,'invoice_id'=>$invoiceId]);
} catch(Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}


