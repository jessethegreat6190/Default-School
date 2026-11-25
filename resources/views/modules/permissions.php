<?php
RBAC::require('settings.manage');
$pdo = DB::pdo(); $error=DB::error(); $message=null; $user=Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);
$roles=[]; $perms=[]; $matrix=[];
if ($pdo && DB::hasTable('permissions')) {
    $roles = $pdo->query("SELECT id,name FROM roles WHERE name NOT IN ('super_admin','school_admin','head_teacher') ORDER BY name")->fetchAll();
    $perms = $pdo->query("SELECT id,code,label,module FROM permissions ORDER BY module,code")->fetchAll();
    if ($_SERVER['REQUEST_METHOD']==='POST' && $canManage) {
        $action=$_POST['action']??'';
        try {
            if ($action==='save_permissions') {
                $pdo->beginTransaction();
                $pdo->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.name NOT IN ('super_admin','school_admin','head_teacher')");
                $ins=$pdo->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id) VALUES (?,?)');
                foreach (($_POST['perm'] ?? []) as $rid => $codes) {
                    foreach ($codes as $pid => $on) { $ins->execute([(int)$rid,(int)$pid]); }
                }
                $pdo->commit();
                Audit::log('rbac.permissions.update','role_permissions',null,null,['roles'=>array_keys($_POST['perm']??[])]);
                $message='Permissions updated.';
            }
        } catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
    }
    $rows = $pdo->query("SELECT role_id,permission_id FROM role_permissions")->fetchAll();
    foreach($rows as $r){ $matrix[$r['role_id']][$r['permission_id']] = true; }
}
?>
<div class="card"><div class="section-title"><h2>Roles & Permissions</h2><span class="badge warn">RBAC</span></div>
<p class="muted">Super/head admins retain full access. Configure permissions for other roles.</p>
<?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?></div>
<div class="card">
<?php if($canManage): ?><form method="post"><?= CSRF::field() ?><input type="hidden" name="action" value="save_permissions">
<div style="overflow:auto;"><table class="compact"><thead><tr><th>Permission</th><?php foreach($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($perms as $p): ?><tr><td><strong><?= e($p['code']) ?></strong><div class="muted" style="font-size:.75rem;"><?= e($p['label']) ?></div></td><?php foreach($roles as $r): $checked=!empty($matrix[$r['id']][$p['id']]); ?><td style="text-align:center;"><input type="checkbox" name="perm[<?= e($r['id']) ?>][<?= e($p['id']) ?>]" <?= $checked?'checked':'' ?>></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<div style="margin-top:12px;"><button class="btn" type="submit">Save Permissions</button></div>
</form><?php endif; ?>
</div>
