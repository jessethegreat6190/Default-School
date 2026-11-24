<?php
$pdo = DB::pdo();
$message = null;
$error = DB::error();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'migrate') {
            if (!$pdo) { DB::createDatabase(); $pdo = DB::pdo(); }
            if (!$pdo) throw new RuntimeException(DB::error() ?: 'Could not connect to MySQL server.');
            $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/001_phase1_foundation.sql');
            $pdo->exec($sql);
            Audit::log('system.migrate', 'migration', '001_phase1_foundation');
            $message = 'Database created and schema migrated successfully.';
        }
        if ($action === 'create_admin') {
            $name = trim((string) ($_POST['name'] ?? 'School Admin'));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $pass = (string) ($_POST['password'] ?? '');
            $schoolId = (int) $pdo->query("SELECT id FROM schools WHERE code='default' LIMIT 1")->fetchColumn();
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name='school_admin' LIMIT 1")->fetchColumn();
            if ($name && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($pass) >= 6 && $schoolId && $roleId) {
                $stmt = $pdo->prepare('INSERT INTO users (school_id,role_id,name,email,password_hash,status) VALUES (?,?,?,?,?,\"active\") ON DUPLICATE KEY UPDATE name=VALUES(name), role_id=VALUES(role_id), password_hash=VALUES(password_hash), status="active"');
                $stmt->execute([$schoolId, $roleId, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                Audit::log('system.create_admin', 'user', $email);
                $message = 'Admin user created/updated. You can now login.';
            } else {
                $error = 'Provide valid name, email and password (min 6 chars).';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$tables = [];
if ($pdo) {
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
}
?>
<div class="grid grid-2">
  <div class="card">
    <div class="section-title"><h2>System Setup</h2><span class="badge <?= $pdo ? 'ok' : 'danger' ?>"><?= $pdo ? 'DB Connected' : 'DB Offline' ?></span></div>
    <p class="muted">Phase 1 foundation setup. Start Apache + MySQL in XAMPP, then run migration.</p>
    <?php if ($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><?= CSRF::field() ?> style="margin-top:12px;">
      <input type="hidden" name="action" value="migrate">
      <button class="btn" type="submit">Run Migration</button>
    </form>
    <div class="empty" style="margin-top:12px;">
      <strong>Tables</strong>
      <div class="muted" style="margin-top:8px;"><?= $tables ? e(implode(', ', $tables)) : 'No tables found yet.' ?></div>
    </div>
  </div>
  <div class="card">
    <div class="section-title"><h2>Create Admin</h2><span class="badge warn">Required</span></div>
    <form method="post"><?= CSRF::field() ?> class="grid">
      <input type="hidden" name="action" value="create_admin">
      <label>Name<input class="input" name="name" value="School Admin" required></label>
      <label>Email<input class="input" name="email" type="email" required></label>
      <label>Password<input class="input" name="password" type="password" minlength="6" required></label>
      <button class="btn" type="submit" <?= $pdo ? '' : 'disabled' ?>>Create / Update Admin</button>
    </form>
  </div>
</div>





