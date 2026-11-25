<?php
// RBAC guard for module pages
$guards = [
    'dashboard' => 'dashboard.view',
    'admissions' => 'students.view',
    'students' => 'students.view',
    'attendance' => 'attendance.view',
    'pickup' => 'pickup.view',
    'academics' => 'academics.view',
    'finance' => 'finance.view',
    'reconciliation' => 'finance.view',
    'communication' => 'dashboard.view',
    'parent_portal' => 'portal.view',
    'transport' => 'dashboard.view',
    'reports' => 'dashboard.view',
    'branding' => 'settings.manage',
    'integrations' => 'settings.manage',
    'permissions' => 'settings.manage',
    'audit' => 'settings.manage',
    'academic' => 'dashboard.view',
];
$perm = $guards[$page] ?? null;
if ($perm) { RBAC::require($perm); }
$view = __DIR__ . '/../modules/' . $page . '.php';
if (file_exists($view)) require $view;
else require __DIR__ . '/../modules/404.php';
?>





