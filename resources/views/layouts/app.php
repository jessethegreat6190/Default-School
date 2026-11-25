<?php
$nav = [
    ['href' => '/dashboard', 'label' => 'Dashboard', 'key' => 'dashboard'],
    ['href' => '/admissions', 'label' => 'Admissions', 'key' => 'admissions'],
    ['href' => '/students', 'label' => 'Students', 'key' => 'students'],
    ['href' => '/attendance', 'label' => 'Attendance', 'key' => 'attendance'],
    ['href' => '/pickup', 'label' => 'Safe Pickup', 'key' => 'pickup'],
    ['href' => '/academics', 'label' => 'Academics', 'key' => 'academics'],
    ['href' => '/finance', 'label' => 'Fees & Finance', 'key' => 'finance'],
    ['href' => '/reconciliation', 'label' => 'Reconciliation', 'key' => 'reconciliation'],
    ['href' => '/communication', 'label' => 'Communication', 'key' => 'communication'],
    ['href' => '/parent-portal', 'label' => 'Parent Portal', 'key' => 'parent_portal'],
    ['href' => '/transport', 'label' => 'Transport', 'key' => 'transport'],
    ['href' => '/reports', 'label' => 'Reports', 'key' => 'reports'],
    ['href' => '/settings/branding', 'label' => 'Branding', 'key' => 'branding'],
    ['href' => '/settings/integrations', 'label' => 'Integrations', 'key' => 'integrations'],
    ['href' => '/settings/permissions', 'label' => 'Permissions', 'key' => 'permissions'],
    ['href' => '/settings/audit', 'label' => 'Audit Log', 'key' => 'audit'],
    ['href' => '/settings/academic', 'label' => 'Academic Setup', 'key' => 'academic'],
    ['href' => '/setup', 'label' => 'Setup', 'key' => 'setup'],
];
$publicPage = in_array($page, ['login','setup','404'], true);
?><?php if (in_array($page, ["report_card_print","receipt_print"], true)) { require __DIR__ . "/../modules/" . $page . ".php"; return; } ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> - <?= e($school['name']) ?></title>
  <link rel="icon" href="<?= e($school['favicon']) ?>">
  <?php foreach (Theme::cssFiles() as $css): ?>
    <link rel="stylesheet" href="<?= e(asset_path($css)) ?>">
  <?php endforeach; ?>
</head>
<body data-page="<?= e($page) ?>">
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <img src="<?= e($school['logo']) ?>" alt="<?= e($school['name']) ?> logo" class="brand-logo">
        <div>
          <div class="brand-name"><?= e($school['name']) ?></div>
          <div class="brand-tagline"><?= e(config('app.tagline')) ?></div>
        </div>
      </div>
      <?php if (!$publicPage): ?>
      <nav class="nav">
        <?php foreach ($nav as $item): ?>
          <a class="nav-link <?= $page === $item['key'] ? 'active' : '' ?>" href="<?= e(asset_path(ltrim($item['href'], '/'))) ?>">
            <?= e($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
      <div class="sidebar-foot">
        Powered by <strong><?= e(config('app.name')) ?></strong>
        <?php if (Auth::check()): ?><div style="margin-top:8px;"><a href="<?= e(asset_path('/logout')) ?>" style="color:#fff;opacity:.9;">Logout</a></div><?php endif; ?>
      </div>
    </aside>
    <main class="main">
      <header class="topbar">
        <div>
          <p class="eyebrow">School Management System</p>
          <h1><?= e($title) ?></h1>
        </div>
        <div class="topbar-right">
          <span class="pill"><?= $dbReady ? 'DB Ready' : 'Setup Needed' ?></span>
          <span class="muted"><?= e($school['short_name'] ?? $school['name']) ?></span>
        </div>
      </header>
      <section class="content">
        <?php require __DIR__ . '/content.php'; ?>
      </section>
    </main>
  </div>
  <script>
    window.SCHOOLGATE = {
      school: <?= json_encode($school, JSON_UNESCAPED_SLASHES) ?>,
      page: <?= json_encode($page) ?>,
      baseUrl: <?= json_encode(config('app.base_url')) ?>,
      dbReady: <?= json_encode((bool)$dbReady) ?>
    };
  </script>
  <?php foreach (Theme::jsFiles() as $js): ?>
    <script src="<?= e(asset_path($js)) ?>" defer></script>
  <?php endforeach; ?>
</body>
</html>








