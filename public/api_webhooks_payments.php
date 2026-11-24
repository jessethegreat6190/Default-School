<?php
// Public webhook endpoint: /api/webhooks/payments?provider=schoolpay&token=...
require_once __DIR__ . '/../app/Core/helpers.php';
require_once __DIR__ . '/../app/Core/DB.php';
require_once __DIR__ . '/../app/Core/Settings.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Core/Audit.php';
require_once __DIR__ . '/../app/Core/PaymentWebhook.php';
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
$res = PaymentWebhook::process($payload, $provider, $schoolId, $headers);
if (!($res['ok'] ?? false) && ($res['status'] ?? '') === 'failed') http_response_code(500);
echo json_encode($res);
