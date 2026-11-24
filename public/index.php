<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/helpers.php';
require_once __DIR__ . '/../app/Core/Theme.php';
require_once __DIR__ . '/../app/Core/DB.php';
require_once __DIR__ . '/../app/Core/Settings.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Core/Audit.php';
require_once __DIR__ . '/../app/Core/RBAC.php';
require_once __DIR__ . '/../app/Core/Notifier.php';
require_once __DIR__ . '/../app/Core/CSRF.php';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim((string) config('app.base_url', ''), '/');
if ($base && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base)) ?: '/';
}
$uri = '/' . trim($uri, '/');

if ($uri === '/api/webhooks/payments') { require __DIR__ . '/api_webhooks_payments.php'; exit; }
CSRF::verify();
Auth::start();

$publicRoutes = ['/login', '/setup'];
$routes = [
    '/' => 'dashboard', '/dashboard' => 'dashboard',
    '/admissions' => 'admissions', '/students' => 'students',
    '/attendance' => 'attendance', '/pickup' => 'pickup',
    '/academics' => 'academics', '/finance' => 'finance',
    '/invoice' => 'invoice_detail',
    '/communication' => 'communication',
    '/parent-portal' => 'parent_portal', '/transport' => 'transport',
    '/reports' => 'reports',
    '/report-card/print' => 'report_card_print',
    '/receipt/print' => 'receipt_print', '/reconciliation' => 'reconciliation', '/settings/branding' => 'branding',
    '/settings/integrations' => 'integrations',
    '/webhook-event' => 'webhook_event',
    '/settings/academic' => 'academic',
    '/setup' => 'setup', '/login' => 'login', '/logout' => 'logout',
];

if ($uri === '/logout') { Audit::log('auth.logout'); Auth::logout(); header('Location: ' . asset_path('/login')); exit; }

$page = $routes[$uri] ?? '404';
if (!in_array($uri, $publicRoutes, true) && $page !== '404') {
    if (!Auth::check()) { header('Location: ' . asset_path('/login')); exit; }
}

$titleMap = [
    'dashboard'=>'Dashboard','admissions'=>'Admissions','students'=>'Students','attendance'=>'Attendance & Timetable',
    'pickup'=>'Safe Pickup','academics'=>'Academics & Report Cards','finance'=>'Fees & Finance','invoice_detail'=>'Invoice Detail',
    'communication'=>'Parent Portal & Communication','parent_portal'=>'Parent Portal','transport'=>'Transport','reports'=>'Reports & Audit','report_card_print'=>'Report Card','receipt_print'=>'Receipt','reconciliation'=>'Payment Reconciliation',
    'branding'=>'School Branding','integrations'=>'Integrations','webhook_event'=>'Webhook Event','academic'=>'Academic Setup','setup'=>'Setup','login'=>'Login','404'=>'Not Found',
];

$title = $titleMap[$page] ?? 'SchoolGate';
$school = config('school');
$dbError = DB::error();
$dbReady = DB::pdo() && DB::hasTable('users');
require __DIR__ . '/../resources/views/layouts/app.php';













