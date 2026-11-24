<?php
declare(strict_types=1);

final class PaymentWebhook
{
    public static function process(array $payload, string $provider, int $schoolId, array $headers = [], ?int $eventId = null): array
    {
        $pdo = DB::pdo();
        if (!$pdo) return ['ok'=>false,'error'=>'db','status'=>'failed'];
        $ref = (string)($payload['provider_reference'] ?? $payload['reference'] ?? $payload['tx_ref'] ?? $payload['transaction_id'] ?? '');
        $amount = (float)($payload['amount'] ?? $payload['paid'] ?? 0);
        $studentNo = (string)($payload['student_no'] ?? $payload['admission_no'] ?? '');
        $invoiceNo = (string)($payload['invoice_no'] ?? '');
        $currency = (string)($payload['currency'] ?? 'UGX');
        $paidAt = date('Y-m-d H:i:s', strtotime((string)($payload['paid_at'] ?? $payload['date'] ?? 'now')));
        $status='received'; $error=null; $paymentId=null; $studentId=null; $invoiceId=null;

        if ($eventId) {
            $pdo->prepare('UPDATE webhook_events SET provider=?, event_type=?, external_ref=?, payload_json=?, headers_json=?, status="received", error_message=NULL, processed_at=NULL WHERE id=?')
                ->execute([$provider,(string)($payload['event']??'payment'),$ref,json_encode($payload),json_encode($headers),$eventId]);
        } else {
            $st=$pdo->prepare('INSERT INTO webhook_events (school_id,provider,event_type,external_ref,payload_json,headers_json,status) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$schoolId,$provider,(string)($payload['event']??'payment'),$ref,json_encode($payload),json_encode($headers),'received']);
            $eventId = (int)$pdo->lastInsertId();
        }

        try {
            if ($ref) {
                $dup=$pdo->prepare('SELECT id FROM payments WHERE provider_reference=? AND school_id=? AND status="posted" LIMIT 1'); $dup->execute([$ref,$schoolId]); $paymentId=$dup->fetchColumn();
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
            return ['ok'=>true,'status'=>$status,'payment_id'=>$paymentId,'student_id'=>$studentId,'invoice_id'=>$invoiceId,'event_id'=>$eventId];
        } catch(Throwable $e) {
            $pdo->prepare('UPDATE webhook_events SET status="failed", error_message=?, processed_at=NOW() WHERE id=?')->execute([$e->getMessage(),$eventId]);
            return ['ok'=>false,'error'=>$e->getMessage(),'status'=>'failed','event_id'=>$eventId];
        }
    }
}
