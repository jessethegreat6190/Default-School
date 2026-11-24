<?php
declare(strict_types=1);

final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $pdo = DB::pdo();
        if (!$pdo || !DB::hasTable('settings')) return $default;
        $st = $pdo->prepare('SELECT value_json FROM settings WHERE school_id=? AND `key`=? LIMIT 1');
        $st->execute([(int)(Auth::user()['school_id'] ?? 1), $key]);
        $row = $st->fetchColumn();
        if ($row === false) return $default;
        $val = json_decode((string)$row, true);
        return $val ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $pdo = DB::pdo();
        if (!$pdo || !DB::hasTable('settings')) return;
        $schoolId = (int)(Auth::user()['school_id'] ?? 1);
        $st = $pdo->prepare('INSERT INTO settings (school_id,`key`,value_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json), updated_at=NOW()');
        $st->execute([$schoolId, $key, json_encode($value)]);
    }
}
