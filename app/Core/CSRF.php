<?php
declare(strict_types=1);

final class CSRF
{
    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        Auth::start();
        $ok = isset($_POST['_csrf'], $_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], (string)$_POST['_csrf']);
        if (!$ok) { http_response_code(419); die('Invalid CSRF token. Refresh and try again.'); }
    }
}
