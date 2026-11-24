<?php
$pdo = DB::pdo();
$message = null; $error = DB::error();
$schoolRow = null;
if ($pdo && DB::hasTable('schools')) {
    $schoolRow = $pdo->query("SELECT * FROM schools WHERE code='default' LIMIT 1")->fetch() ?: null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && DB::hasTable('schools')) {
    try {
        $stmt = $pdo->prepare("UPDATE schools SET name=?, short_name=?, motto=?, primary_color=?, secondary_color=?, accent_color=?, phone=?, email=?, website=?, address=? WHERE code='default'");
        $stmt->execute([
            trim((string)$_POST['name']), trim((string)$_POST['short_name']), trim((string)$_POST['motto']),
            trim((string)$_POST['primary_color']), trim((string)$_POST['secondary_color']), trim((string)$_POST['accent_color']),
            trim((string)$_POST['phone']), trim((string)$_POST['email']), trim((string)$_POST['website']), trim((string)$_POST['address'])
        ]);
        Audit::log('school.branding.update', 'school', 'default', $schoolRow, $_POST);
        $message = 'Branding saved. Theme colors apply on next page load for default theme.';
        $schoolRow = $pdo->query("SELECT * FROM schools WHERE code='default' LIMIT 1")->fetch() ?: null;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$s = $schoolRow ?: $school;
?>
<div class="grid grid-2">
  <div class="card">
    <div class="section-title"><h2>School Branding</h2><span class="badge warn">Per-school config</span></div>
    <p class="muted">School identity is data-driven. Core CSS/JS stay shared; school overrides go in <code>themes/{school_code}/</code>.</p>
    <?php if ($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><?= CSRF::field() ?> class="grid" style="margin-top:12px;">
      <label>School name<input class="input" name="name" value="<?= e($s['name'] ?? '') ?>" required></label>
      <label>Short name<input class="input" name="short_name" value="<?= e($s['short_name'] ?? '') ?>"></label>
      <label>Motto<input class="input" name="motto" value="<?= e($s['motto'] ?? '') ?>"></label>
      <div class="grid grid-2">
        <label>Primary color<input class="input" name="primary_color" value="<?= e($s['primary_color'] ?? '#1f6feb') ?>"></label>
        <label>Secondary color<input class="input" name="secondary_color" value="<?= e($s['secondary_color'] ?? '#0b3d91') ?>"></label>
      </div>
      <label>Accent color<input class="input" name="accent_color" value="<?= e($s['accent_color'] ?? '#f4b400') ?>"></label>
      <label>Phone<input class="input" name="phone" value="<?= e($s['phone'] ?? '') ?>"></label>
      <label>Email<input class="input" name="email" value="<?= e($s['email'] ?? '') ?>"></label>
      <label>Website<input class="input" name="website" value="<?= e($s['website'] ?? '') ?>"></label>
      <label>Address<textarea class="input" name="address" rows="3"><?= e($s['address'] ?? '') ?></textarea></label>
      <button class="btn" type="submit" <?= $pdo ? '' : 'disabled' ?>>Save Branding</button>
    </form>
  </div>
  <div class="card">
    <div class="section-title"><h2>Theme File Separation</h2><span class="badge ok">Required</span></div>
    <div class="empty">
      <ul class="list">
        <li><strong>Core CSS:</strong> <code>resources/css/core/*</code> shared by all schools.</li>
        <li><strong>School CSS:</strong> <code>resources/css/themes/{school_code}/theme.css</code>.</li>
        <li><strong>Core JS:</strong> <code>resources/js/core/*</code> shared behavior.</li>
        <li><strong>School JS:</strong> <code>resources/js/themes/{school_code}/theme.js</code> hooks only.</li>
        <li><strong>Logos/assets:</strong> <code>public/schools/{school_code}/</code>.</li>
        <li><strong>Identity data:</strong> <code>schools</code> table/config; never hardcode in templates.</li>
      </ul>
    </div>
  </div>
</div>



