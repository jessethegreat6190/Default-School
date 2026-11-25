<?php
declare(strict_types=1);

final class RBAC
{
    public static function can(string $permission): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        $role = $user['role_name'] ?? '';
        if ($role === 'super_admin' || $role === 'school_admin' || $role === 'head_teacher') return true;

        $pdo = DB::pdo();
        if ($pdo && DB::hasTable('role_permissions') && DB::hasTable('permissions') && DB::hasTable('roles')) {
            $st = $pdo->prepare('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name=? AND p.code=? LIMIT 1');
            $st->execute([$role, $permission]);
            if ($st->fetchColumn()) return true;
        }

        $map = [
            'teacher' => ['attendance.view','attendance.manage','academics.view','academics.manage','pickup.view','students.view','dashboard.view'],
            'finance' => ['finance.view','finance.manage','students.view','dashboard.view'],
            'gate_staff' => ['pickup.view','pickup.manage','attendance.view','students.view','dashboard.view'],
            'parent' => ['portal.view','dashboard.view'],
        ];
        return in_array($permission, $map[$role] ?? ['dashboard.view'], true);
    }

    public static function require(string $permission): void
    {
        if (!self::can($permission)) {
            http_response_code(403);
            echo '<div class="card"><div class="section-title"><h2>Access denied</h2><span class="badge danger">403</span></div><p class="muted">You do not have permission to access this area.</p></div>';
            exit;
        }
    }
}
