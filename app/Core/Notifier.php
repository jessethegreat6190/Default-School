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
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
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

final class Notifier
{
    public static function providerFor(string $channel): NotificationProvider
    {
        $cfg = Settings::get('notifications', []);
        $driver = $cfg[$channel]['driver'] ?? $cfg['default']['driver'] ?? 'log';
        if ($driver === 'webhook') {
            $url = (string)($cfg[$channel]['webhook_url'] ?? $cfg['default']['webhook_url'] ?? '');
            $secret = (string)($cfg[$channel]['webhook_secret'] ?? $cfg['default']['webhook_secret'] ?? '');
            if ($url !== '') return new WebhookNotificationProvider($url, $secret);
        }
        if ($channel === 'email') {
            $from = (string)($cfg['email']['from'] ?? '');
            if (($cfg['email']['driver'] ?? 'mail') === 'mail') return new EmailNotificationProvider($from);
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
        $res = ['ok'=>false,'error'=>'not attempted'];
        for ($i=1; $i<=$maxAttempts; $i++) {
            $res = self::providerFor((string)$n['channel'])->send((string)$n['channel'], $contact, (string)$n['message'], ['category'=>$n['category'],'title'=>$n['title']]);
            if (DB::hasTable('notification_attempts')) {
                $pdo->prepare('INSERT INTO notification_attempts (notification_id,attempt_no,status,provider_ref,error_message) VALUES (?,?,?,?,?)')
                    ->execute([$notificationId,$i,$res['ok']?'sent':'failed',$res['ref']??null,$res['error']??null]);
            }
            if ($res['ok']) break;
        }
        $pdo->prepare('UPDATE notifications SET status=?, provider_ref=?, sent_at=? WHERE id=?')
            ->execute([$res['ok'] ? 'sent' : 'failed', $res['ref'] ?? ($res['error'] ?? null), $res['ok'] ? date('Y-m-d H:i:s') : null, $notificationId]);
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
