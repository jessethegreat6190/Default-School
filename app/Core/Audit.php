<?php
declare(strict_types=1);

final class Audit
{
    public static function log(string $action, ?string $entityType = null, string|int|null $entityId = null, mixed $old = null, mixed $new = null): void
    {
        $pdo = DB::pdo();
        if (!$pdo || !DB::hasTable('audit_logs')) return;
        $user = Auth::user();
        $schoolId = $user['school_id'] ?? config('school.id');
        $stmt = $pdo->prepare('INSERT INTO audit_logs (school_id,user_id,action,entity_type,entity_id,old_values,new_values,ip_address) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $schoolId ?: null,
            $user['id'] ?? null,
            $action,
            $entityType,
            $entityId !== null ? (string) $entityId : null,
            $old !== null ? json_encode($old) : null,
            $new !== null ? json_encode($new) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
