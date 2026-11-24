<?php
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (Auth::attempt($email, $password)) {
        Audit::log('auth.login', 'user', Auth::user()['id'] ?? null);
        header('Location: ' . asset_path('/dashboard'));
        exit;
    }
    $error = DB::pdo() ? 'Invalid email or password.' : 'Database not connected. Run setup after starting MySQL.';
}
?>
<div class="card" style="max-width:520px;margin:40px auto;">
  <div class="section-title"><h2>Sign in</h2><span class="badge warn">Foundation</span></div>
  <p class="muted">Sign in to manage admissions, attendance, safe pickup, academics and finance.</p>
  <?php if ($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$dbReady): ?>
    <div class="empty" style="margin-top:12px;">
      Database not ready. Start MySQL in XAMPP, then open <a href="<?= e(asset_path('/setup')) ?>"><strong>Setup</strong></a>.
    </div>
  <?php endif; ?>
  <form method="post"><?= CSRF::field() ?> class="grid" style="margin-top:16px;">
    <label>Email<input class="input" name="email" type="email" required></label>
    <label>Password<input class="input" name="password" type="password" required></label>
    <button class="btn" type="submit">Login</button>
  </form>
</div>



