<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/helpers.php';
require_once __DIR__ . '/../app/Core/DB.php';
require_once __DIR__ . '/../app/Core/Settings.php';
require_once __DIR__ . '/../app/Core/Notifier.php';
$pdo = DB::pdo();
if (!$pdo || !DB::hasTable('notifications')) { echo "db not ready\n"; exit(1); }
$st = $pdo->prepare('SELECT id FROM notifications WHERE status IN ("queued","failed") ORDER BY id ASC LIMIT 50');
$st->execute();
$ids = $st->fetchAll(PDO::FETCH_COLUMN);
$n = 0;
foreach ($ids as $id) { $res = Notifier::dispatch((int)$id); if ($res['ok']) $n++; }
echo "processed=" . count($ids) . " sent=" . $n . "\n";
