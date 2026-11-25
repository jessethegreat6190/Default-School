<?php
RBAC::require('settings.manage');
$pdo=DB::pdo(); $schoolId=(int)(Auth::user()['school_id']??1);
$q=trim((string)($_GET['q']??'')); $limit=100; $where='WHERE school_id=?'; $params=[$schoolId];
if($q!==''){ $where.=' AND (action LIKE ? OR entity_type LIKE ? OR entity_id LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; }
$rows=[]; if($pdo && DB::hasTable('audit_logs')){ $st=$pdo->prepare("SELECT a.*, u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id $where ORDER BY a.id DESC LIMIT $limit"); $st->execute($params); $rows=$st->fetchAll(); }
?>
<div class="card"><div class="section-title"><h2>Audit Log</h2><span class="badge warn">Traceability</span></div>
<form method="get" class="grid grid-3" style="margin-top:12px;"><label>Search<input class="input" name="q" value="<?= e($q) ?>" placeholder="action/entity/id"></label><div style="display:flex;align-items:end;"><button class="btn secondary">Filter</button></div></form></div>
<div class="card"><table class="compact"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['user_name']?:'system') ?></td><td><?= e($r['action']) ?></td><td><?= e(($r['entity_type']?:'-').($r['entity_id']?' #'.$r['entity_id']:'')) ?></td><td><?= e($r['ip']?:'-') ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="5" class="muted">No audit entries.</td></tr><?php endif; ?>
</tbody></table></div>
