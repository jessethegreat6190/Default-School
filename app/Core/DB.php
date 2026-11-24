<?php
declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;
    private static ?PDO $serverPdo = null;
    private static ?string $error = null;

    public static function serverPdo(): ?PDO
    {
        if (self::$serverPdo instanceof PDO) return self::$serverPdo;
        $cfg = config('database');
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
        try {
            self::$serverPdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            self::$error = $e->getMessage();
            self::$serverPdo = null;
        }
        return self::$serverPdo;
    }

    public static function pdo(): ?PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $cfg = config('database');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$error = null;
        } catch (Throwable $e) {
            self::$error = $e->getMessage();
            self::$pdo = null;
        }
        return self::$pdo;
    }

    public static function createDatabase(): bool
    {
        $server = self::serverPdo();
        if (!$server) return false;
        $db = preg_replace('/[^a-zA-Z0-9_]/', '', (string) config('database.name', 'schoolgate'));
        $server->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$pdo = null;
        return self::pdo() instanceof PDO;
    }

    public static function error(): ?string
    {
        self::pdo();
        return self::$error;
    }

    public static function hasTable(string $table): bool
    {
        $pdo = self::pdo();
        if (!$pdo) return false;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([config('database.name'), $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
