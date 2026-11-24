<?php
Auth::requireLogin();
RBAC::require('dashboard.view');
$pdo = DB::pdo(); $schoolId=(int)(Auth::user()['school_id']??1); $id=(int)($_GET['id']??0);
$ev=null; if($pdo && $id){ $st=$pdo->prepare('SELECT * FROM webhook_events WHERE id=? AND school_id=?'); $st->execute([$id,$schoolId]); $ev=$st->fetch(); }
?>
<div class="card"><div class="section-title"><h2>Webhook Event</h2><a class="btn secondary" href="<?= e(asset_path('/settings/integrations')) ?>">Back</a></div>
<?php if(!$ev): ?><p class="muted">Event not found.</p><?php else: ?>
<table class="compact"><tbody>
<tr><th>ID</th><td><?= e($ev['id']) ?></td></tr>
<tr><th>Provider</th><td><?= e($ev['provider']) ?></td></tr>
<tr><th>Event</th><td><?= e($ev['event_type']?:'-') ?></td></tr>
<tr><th>Reference</th><td><?= e($ev['external_ref']?:'-') ?></td></tr>
<tr><th>Status</th><td><span class="badge <?= in_array($ev['status'],['posted','posted_unallocated'])?'ok':'warn' ?>"><?= e($ev['status']) ?></span></td></tr>
<tr><th>Error</th><td><?= e($ev['error_message']?:'-') ?></td></tr>
<tr><th>Processed</th><td><?= e($ev['processed_at']?:'-') ?></td></tr>
</tbody></table>
<h3 style="margin-top:16px;">Payload</h3><pre style="white-space:pre-wrap;background:#f7f9fc;padding:12px;border-radius:8px;"><?= e(json_encode(json_decode($ev['payload_json']?:'{}'), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre>
<?php endif; ?></div>
