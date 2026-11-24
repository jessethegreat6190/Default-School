<?php
declare(strict_types=1);

interface NotificationProvider
{
    public function send(string $channel, string $to, string $message, array $meta = []): array; // ['ok'=>bool,'ref'=>?, 'error'=>?]
}

final class LogNotificationProvider implements NotificationProvider
{
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        $line = sprintf("[%s] %s to %s: %s\n", date('c'), strtoupper($channel), $to, $message);
        @file_put_contents(dirname(__DIR__, 2) . '/storage/logs/notifications.log', $line, FILE_APPEND);
        return ['ok' => true, 'ref' => 'log-' . bin2hex(random_bytes(4))];
    }
}

final class WebhookNotificationProvider implements NotificationProvider
{
    public function __construct(private string $url, private string $secret = '') {}
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        $payload = json_encode(['channel'=>$channel,'to'=>$to,'message'=>$message,'meta'=>$meta,'ts'=>time()]);
        $headers = ['Content-Type: application/json'];
        if ($this->secret !== '') $headers[] = 'X-SchoolGate-Signature: ' . hash_hmac('sha256', (string)$payload, $this->secret);
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false || $http >= 400) return ['ok'=>false,'error'=>$err ?: "HTTP $http: ".substr((string)$body,0,200)];
        $json = json_decode((string)$body, true);
        return ['ok'=>true,'ref'=>$json['id'] ?? $json['ref'] ?? "http-$http"];
    }
}

final class EmailNotificationProvider implements NotificationProvider
{
    public function __construct(private string $from = '') {}
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        $subject = $meta['title'] ?? 'SchoolGate Notification';
        $headers = 'Content-Type: text/plain; charset=UTF-8';
        if ($this->from) $headers .= "\r\nFrom: " . $this->from;
        $ok = @mail($to, $subject, $message, $headers);
        return $ok ? ['ok'=>true,'ref'=>'mail-'.bin2hex(random_bytes(4))] : ['ok'=>false,'error'=>'mail() failed'];
    }
}

final class AfricasTalkingProvider implements NotificationProvider
{
    public function __construct(private array $cfg) {}
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        if ($channel !== 'sms') return ['ok'=>false,'error'=>'AfricasTalking supports sms only'];
        $url = rtrim((string)($this->cfg['at_base_url'] ?? 'https://api.africastalking.com/version1'),'/').'/messaging';
        $username = (string)($this->cfg['at_username'] ?? '');
        $apiKey = (string)($this->cfg['at_api_key'] ?? '');
        $from = (string)($this->cfg['at_from'] ?? '');
        if ($username==='' || $apiKey==='') return ['ok'=>false,'error'=>'AfricasTalking username/api key missing'];
        $post = http_build_query(array_filter(['username'=>$username,'to'=>$to,'message'=>$message,'from'=>$from]));
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Accept: application/json','apiKey: '.$apiKey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        $body=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if($body===false || $http>=400) return ['ok'=>false,'error'=>$err ?: "HTTP $http: ".substr((string)$body,0,200)];
        $json=json_decode((string)$body,true); $rec=$json['SMSMessageData']['Recipients'][0] ?? []; $status=$rec['status'] ?? null; $id=$rec['messageId'] ?? ($json['SMSMessageData']['Message'] ?? null);
        return ($status && stripos((string)$status,'success')!==false) ? ['ok'=>true,'ref'=>(string)$id] : ['ok'=>false,'error'=>(string)($status ?: substr((string)$body,0,200)),'ref'=>(string)$id];
    }
}

final class TwilioProvider implements NotificationProvider
{
    public function __construct(private array $cfg) {}
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        $sid=(string)($this->cfg['twilio_sid'] ?? ''); $token=(string)($this->cfg['twilio_token'] ?? '');
        $from=(string)($this->cfg['twilio_from'] ?? ''); $waFrom=(string)($this->cfg['twilio_whatsapp_from'] ?? '');
        if($sid===''||$token==='') return ['ok'=>false,'error'=>'Twilio SID/token missing'];
        $url="https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        $fromVal = $channel==='whatsapp' ? ($waFrom ?: $from) : $from;
        $toVal = $channel==='whatsapp' && !str_starts_with($to,'whatsapp:') ? 'whatsapp:'.$to : $to;
        $fromVal = ($channel==='whatsapp' && $fromVal && !str_starts_with($fromVal,'whatsapp:')) ? 'whatsapp:'.$fromVal : $fromVal;
        $post=http_build_query(['To'=>$toVal,'From'=>$fromVal,'Body'=>$message]);
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_USERPWD=>$sid.':'.$token,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        $body=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if($body===false || $http>=400) return ['ok'=>false,'error'=>$err ?: "HTTP $http: ".substr((string)$body,0,200)];
        $json=json_decode((string)$body,true); return !empty($json['sid']) ? ['ok'=>true,'ref'=>(string)$json['sid']] : ['ok'=>false,'error'=>substr((string)$body,0,200)];
    }
}

final class MetaWhatsAppProvider implements NotificationProvider
{
    public function __construct(private array $cfg) {}
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        if ($channel!=='whatsapp') return ['ok'=>false,'error'=>'Meta provider supports whatsapp only'];
        $token=(string)($this->cfg['meta_token'] ?? ''); $phoneId=(string)($this->cfg['meta_phone_number_id'] ?? '');
        if($token===''||$phoneId==='') return ['ok'=>false,'error'=>'Meta token/phone number id missing'];
        $url="https://graph.facebook.com/v20.0/$phoneId/messages";
        $payload=json_encode(['messaging_product'=>'whatsapp','to'=>preg_replace('/[^0-9]/','',$to),'type'=>'text','text'=>['body'=>$message]]);
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        $body=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if($body===false || $http>=400) return ['ok'=>false,'error'=>$err ?: "HTTP $http: ".substr((string)$body,0,200)];
        $json=json_decode((string)$body,true); $id=$json['messages'][0]['id'] ?? null; return $id ? ['ok'=>true,'ref'=>(string)$id] : ['ok'=>false,'error'=>substr((string)$body,0,200)];
    }
}

final class Notifier
{
    public static function providerFor(string $channel): NotificationProvider
    {
        $cfg = Settings::get('notifications', []);
        $c = $cfg[$channel] ?? [];
        $driver = $c['driver'] ?? $cfg['default']['driver'] ?? 'log';
        if ($driver === 'webhook') {
            $url = (string)($c['webhook_url'] ?? $cfg['default']['webhook_url'] ?? '');
            $secret = (string)($c['webhook_secret'] ?? $cfg['default']['webhook_secret'] ?? '');
            if ($url !== '') return new WebhookNotificationProvider($url, $secret);
        }
        if ($driver === 'africastalking') return new AfricasTalkingProvider($c);
        if ($driver === 'twilio') return new TwilioProvider($c);
        if ($driver === 'meta_whatsapp') return new MetaWhatsAppProvider($c);
        if ($channel === 'email' && ($driver === 'mail' || ($cfg['email']['driver'] ?? 'mail') === 'mail')) {
            return new EmailNotificationProvider((string)($cfg['email']['from'] ?? ''));
        }
        return new LogNotificationProvider();
    }

    public static function queue(int $schoolId, string $recipientType, ?string $contact, string $channel, string $category, string $title, string $message, ?string $relatedType = null, string|int|null $relatedId = null, ?int $createdBy = null, string $status = 'queued'): int
    {
        $pdo = DB::pdo();
        if (!$pdo || !DB::hasTable('notifications')) return 0;
        $stmt = $pdo->prepare('INSERT INTO notifications (school_id,recipient_type,recipient_contact,channel,category,title,message,status,related_type,related_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$schoolId, $recipientType, $contact, $channel, $category, $title, $message, $status, $relatedType, $relatedId !== null ? (string)$relatedId : null, $createdBy]);
        return (int)$pdo->lastInsertId();
    }

    public static function dispatch(int $notificationId, int $maxAttempts = 2): array
    {
        $pdo = DB::pdo();
        if (!$pdo) return ['ok'=>false,'error'=>'db'];
        $st = $pdo->prepare('SELECT * FROM notifications WHERE id=? LIMIT 1'); $st->execute([$notificationId]); $n = $st->fetch();
        if (!$n) return ['ok'=>false,'error'=>'not found'];
        $contact = (string)($n['recipient_contact'] ?? '');
        if ($contact === '') { $pdo->prepare('UPDATE notifications SET status=? WHERE id=?')->execute(['failed',$notificationId]); return ['ok'=>false,'error'=>'no contact']; }
        $res = ['ok'=>false,'error'=>'not attempted']; $attempts=0;
        for ($i=1; $i<=$maxAttempts; $i++) {
            $attempts=$i;
            $res = self::providerFor((string)$n['channel'])->send((string)$n['channel'], $contact, (string)$n['message'], ['category'=>$n['category'],'title'=>$n['title']]);
            if (DB::hasTable('notification_attempts')) {
                $pdo->prepare('INSERT INTO notification_attempts (notification_id,attempt_no,status,provider_ref,error_message) VALUES (?,?,?,?,?)')
                    ->execute([$notificationId,$i,$res['ok']?'sent':'failed',$res['ref']??null,$res['error']??null]);
            }
            if ($res['ok']) break;
        }
        $set = 'status=?, provider_ref=?, sent_at=?'; $params = [$res['ok'] ? 'sent' : 'failed', $res['ref'] ?? ($res['error'] ?? null), $res['ok'] ? date('Y-m-d H:i:s') : null];
        if (DB::hasTable('notifications')) { $cols=$pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN); if(in_array('attempt_count',$cols,true)){ $set .= ', attempt_count=COALESCE(attempt_count,0)+?'; $params[]=$attempts; } }
        $params[]=$notificationId;
        $pdo->prepare("UPDATE notifications SET $set WHERE id=?")->execute($params);
        return $res;
    }

    public static function queueAndSend(int $schoolId, string $recipientType, ?string $contact, string $channel, string $category, string $title, string $message, ?string $relatedType = null, string|int|null $relatedId = null, ?int $createdBy = null): void
    {
        $id = self::queue($schoolId, $recipientType, $contact, $channel, $category, $title, $message, $relatedType, $relatedId, $createdBy, 'queued');
        if ($id) self::dispatch($id);
    }

    public static function studentGuardianContacts(int $schoolId, int $studentId): array
    {
        $pdo = DB::pdo();
        if (!$pdo || !DB::hasTable('guardians')) return [];
        $sql = 'SELECT g.phone, g.email, g.first_name, g.last_name FROM guardians g JOIN student_guardians sg ON sg.guardian_id=g.id WHERE g.school_id=? AND sg.student_id=?';
        $st = $pdo->prepare($sql); $st->execute([$schoolId, $studentId]);
        $rows = $st->fetchAll();
        if (!$rows) {
            $st = $pdo->prepare('SELECT emergency_contact_phone AS phone, emergency_contact_name AS first_name, "" AS last_name, "" AS email FROM students WHERE id=? AND school_id=?');
            $st->execute([$studentId, $schoolId]);
            $r = $st->fetch();
            if ($r && !empty($r['phone'])) $rows[] = $r;
        }
        return $rows;
    }

    public static function notifyStudentGuardians(int $schoolId, int $studentId, string $category, string $title, string $message, ?string $relatedType = null, string|int|null $relatedId = null, ?int $createdBy = null): int
    {
        $count = 0;
        foreach (self::studentGuardianContacts($schoolId, $studentId) as $g) {
            $contact = $g['phone'] ?: ($g['email'] ?: null);
            $channel = !empty($g['phone']) ? 'sms' : (!empty($g['email']) ? 'email' : null);
            if (!$channel) continue;
            self::queueAndSend($schoolId, 'parent', $contact, $channel, $category, $title, $message, $relatedType, $relatedId, $createdBy);
            $count++;
        }
        return $count;
    }
}
