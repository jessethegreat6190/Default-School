<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $dir = dirname(__DIR__, 2) . '/storage/sessions';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            session_save_path($dir);
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $pdo = DB::pdo();
        if (!$pdo) return false;
        $stmt = $pdo->prepare('SELECT u.*, r.name AS role_name, r.label AS role_label FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.status="active" LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) return false;
        unset($user['password_hash']);
        self::start();
        $_SESSION['user'] = $user;
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . asset_path('/login'));
            exit;
        }
    }
}
