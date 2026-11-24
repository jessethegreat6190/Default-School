<?php
Auth::requireLogin();
RBAC::require('dashboard.view');
$pdo = DB::pdo(); $schoolId=(int)(Auth::user()['school_id']??1); $id=(int)($_GET['id']??0);
$user=Auth::user(); $canManage=in_array($user['role_name']??'', ['super_admin','school_admin','head_teacher','finance'], true);
$message=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST' && $canManage && $pdo && $id) {
  $action=$_POST['action']??'';
  try {
    if ($action==='replay_webhook') {
      $st=$pdo->prepare('SELECT * FROM webhook_events WHERE id=? AND school_id=?'); $st->execute([$id,$schoolId]); $evRow=$st->fetch();
      if(!$evRow) throw new RuntimeException('Event not found.');
      $payload=json_decode($evRow['payload_json']?:'{}', true); if(!is_array($payload)) $payload=[];
      $headers=json_decode($evRow['headers_json']?:'{}', true); if(!is_array($headers)) $headers=[];
      $res=PaymentWebhook::process($payload,(string)$evRow['provider'],$schoolId,$headers,$id);
      Audit::log('webhook.replay','webhook_event',$id,null,$res);
      $message = ($res['ok']??false) ? 'Webhook replayed: '.($res['status']??'ok') : 'Replay failed: '.($res['error']??'unknown');
    }
  } catch(Throwable $e){ $error=$e->getMessage(); }
}
$ev=null; if($pdo && $id){ $st=$pdo->prepare('SELECT * FROM webhook_events WHERE id=? AND school_id=?'); $st->execute([$id,$schoolId]); $ev=$st->fetch(); }
?>
<div class="card"><div class="section-title"><h2>Webhook Event</h2><a class="btn secondary" href="<?= e(asset_path('/settings/integrations')) ?>">Back</a></div>
<?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
<?php if(!$ev): ?><p class="muted">Event not found.</p><?php else: ?>
<?php if($canManage): ?><form method="post" style="margin-bottom:12px;"><?= CSRF::field() ?><input type="hidden" name="action" value="replay_webhook"><button class="btn secondary" type="submit">Replay Event</button></form><?php endif; ?>
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

