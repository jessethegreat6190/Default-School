<?php
RBAC::require('dashboard.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);
$pay = Settings::get('payments', ['webhook_token'=>'','school_id'=>(int)(Auth::user()['school_id']??1)]);
$cfg = Settings::get('notifications', [
  'default'=>['driver'=>'log','webhook_url'=>'','webhook_secret'=>''],
  'sms'=>['driver'=>'log','webhook_url'=>'','webhook_secret'=>''],
  'whatsapp'=>['driver'=>'log','webhook_url'=>'','webhook_secret'=>''],
  'email'=>['driver'=>'mail','from'=>'','webhook_url'=>'','webhook_secret'=>''],
]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_notifications') {
            $cfg = [
              'default'=>['driver'=>$_POST['default_driver']??'log','webhook_url'=>trim($_POST['default_webhook_url']??''),'webhook_secret'=>trim($_POST['default_webhook_secret']??'')],
              'sms'=>['driver'=>$_POST['sms_driver']??'log','webhook_url'=>trim($_POST['sms_webhook_url']??''),'webhook_secret'=>trim($_POST['sms_webhook_secret']??''),'at_username'=>trim($_POST['sms_at_username']??''),'at_api_key'=>trim($_POST['sms_at_api_key']??''),'at_from'=>trim($_POST['sms_at_from']??''),'twilio_sid'=>trim($_POST['sms_twilio_sid']??''),'twilio_token'=>trim($_POST['sms_twilio_token']??''),'twilio_from'=>trim($_POST['sms_twilio_from']??'')],
              'whatsapp'=>['driver'=>$_POST['whatsapp_driver']??'log','webhook_url'=>trim($_POST['whatsapp_webhook_url']??''),'webhook_secret'=>trim($_POST['whatsapp_webhook_secret']??''),'twilio_sid'=>trim($_POST['whatsapp_twilio_sid']??''),'twilio_token'=>trim($_POST['whatsapp_twilio_token']??''),'twilio_from'=>trim($_POST['whatsapp_twilio_from']??''),'twilio_whatsapp_from'=>trim($_POST['whatsapp_twilio_whatsapp_from']??''),'meta_token'=>trim($_POST['whatsapp_meta_token']??''),'meta_phone_number_id'=>trim($_POST['whatsapp_meta_phone_number_id']??'')],
              'email'=>['driver'=>$_POST['email_driver']??'mail','from'=>trim($_POST['email_from']??''),'webhook_url'=>trim($_POST['email_webhook_url']??''),'webhook_secret'=>trim($_POST['email_webhook_secret']??'')],
            ];
            Settings::set('notifications', $cfg);
            Audit::log('settings.notifications.update','settings','notifications',null,$cfg);
            $message='Notification settings saved.';
        }
        if ($action === 'save_payments') {
            $pay = ['webhook_token'=>trim($_POST['webhook_token'] ?? ''), 'school_id'=>(int)($user['school_id']??1)];
            Settings::set('payments', $pay);
            Audit::log('settings.payments.update','settings','payments',null,['webhook_token_set'=>$pay['webhook_token']!=='']);
            $message='Payment webhook settings saved.';
        }
        if ($action === 'test_notification') {
            $ch = $_POST['channel'] ?? 'sms';
            $to = trim((string)($_POST['to'] ?? ''));
            $msg = trim((string)($_POST['message'] ?? 'SchoolGate test notification'));
            $id = Notifier::queue((int)($user['school_id']??1),'test',$to,$ch,'test','Test notification',$msg,'settings','notifications',$user['id']);
            $res = Notifier::dispatch($id);
            $message = $res['ok'] ? 'Test sent. Ref: '.($res['ref']??'ok') : 'Test failed: '.($res['error']??'unknown');
        }
        if ($action === 'retry_notification') {
            $nid = (int)($_POST['notification_id'] ?? 0);
            $res = Notifier::dispatch($nid);
            Audit::log('notification.retry','notification',$nid,null,$res);
            $message = $res['ok'] ? 'Notification resent.' : 'Retry failed: '.($res['error']??'unknown');
        }
    } catch(Throwable $e){ $error=$e->getMessage(); }
}
function drv($cfg,$k,$f,$d='log'){ return e($cfg[$k][$f] ?? $d); }
?>
<div class="card"><div class="section-title"><h2>Integration Settings</h2><span class="badge warn">Providers</span></div>
<p class="muted">Configure SMS/WhatsApp/email delivery. Use <code>log</code> for local dev, <code>webhook</code> to post to your provider gateway, or <code>mail</code> for email (PHP mail). Webhook payload includes channel, to, message, meta and HMAC signature if secret is set.</p>
<?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?></div>
<div class="grid grid-2">
  <div class="card"><h3>Delivery Providers</h3>
    <?php if($canManage): ?><form method="post" class="grid"><?= CSRF::field() ?><input type="hidden" name="action" value="save_notifications">
      <?php foreach(['sms'=>'SMS','whatsapp'=>'WhatsApp','email'=>'Email'] as $k=>$label): $c=$cfg[$k]??[]; ?>
      <div class="empty"><strong><?= $label ?></strong>
        <div class="grid" style="margin-top:8px;">
          <label>Driver<select class="input" name="<?= $k ?>_driver">
            <option value="log" <?= ($c['driver']??'')==='log'?'selected':'' ?>>log</option>
            <option value="webhook" <?= ($c['driver']??'')==='webhook'?'selected':'' ?>>webhook</option>
            <?php if($k==='email'): ?><option value="mail" <?= ($c['driver']??'')==='mail'?'selected':'' ?>>mail()</option><?php endif; ?>
            <?php if($k==='sms'): ?><option value="africastalking" <?= ($c['driver']??'')==='africastalking'?'selected':'' ?>>africastalking</option><option value="twilio" <?= ($c['driver']??'')==='twilio'?'selected':'' ?>>twilio</option><?php endif; ?>
            <?php if($k==='whatsapp'): ?><option value="twilio" <?= ($c['driver']??'')==='twilio'?'selected':'' ?>>twilio</option><option value="meta_whatsapp" <?= ($c['driver']??'')==='meta_whatsapp'?'selected':'' ?>>meta_whatsapp</option><?php endif; ?>
          </select></label>
          <?php if($k==='email'): ?><label>From<input class="input" name="email_from" value="<?= e($c['from']??'') ?>"></label><?php endif; ?>
          <label>Webhook URL<input class="input" name="<?= $k ?>_webhook_url" value="<?= e($c['webhook_url']??'') ?>"></label>
          <label>Webhook secret<input class="input" name="<?= $k ?>_webhook_secret" value="<?= e($c['webhook_secret']??'') ?>"></label>
          <?php if($k==='sms'): ?><label>Africa's Talking username<input class="input" name="sms_at_username" value="<?= e($c['at_username']??'') ?>"></label><label>Africa's Talking API key<input class="input" name="sms_at_api_key" value="<?= e($c['at_api_key']??'') ?>"></label><label>Africa's Talking from<input class="input" name="sms_at_from" value="<?= e($c['at_from']??'') ?>"></label><?php endif; ?>
          <?php if($k==='sms' || $k==='whatsapp'): ?><label>Twilio SID<input class="input" name="<?= $k ?>_twilio_sid" value="<?= e($c['twilio_sid']??'') ?>"></label><label>Twilio token<input class="input" name="<?= $k ?>_twilio_token" value="<?= e($c['twilio_token']??'') ?>"></label><label>Twilio from<input class="input" name="<?= $k ?>_twilio_from" value="<?= e($c['twilio_from']??'') ?>"></label><?php endif; ?>
          <?php if($k==='whatsapp'): ?><label>Twilio WhatsApp from<input class="input" name="whatsapp_twilio_whatsapp_from" value="<?= e($c['twilio_whatsapp_from']??'') ?>"></label><label>Meta token<input class="input" name="whatsapp_meta_token" value="<?= e($c['meta_token']??'') ?>"></label><label>Meta phone number id<input class="input" name="whatsapp_meta_phone_number_id" value="<?= e($c['meta_phone_number_id']??'') ?>"></label><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <button class="btn" type="submit">Save Settings</button>
    </form><?php endif; ?>
  </div>
  <div class="card"><h3>Payment Gateway Webhook</h3>
    <?php if($canManage): ?><form method="post" class="grid"><?= CSRF::field() ?><input type="hidden" name="action" value="save_payments">
      <label>Webhook token<input class="input" name="webhook_token" value="<?= e($pay['webhook_token']??'') ?>" placeholder="shared secret token"></label>
      <button class="btn" type="submit">Save Payment Webhook</button>
    </form><?php endif; ?>
    <div class="empty" style="margin-top:12px;"><strong>Endpoint</strong>
      <div class="muted" style="font-size:.85rem;margin-top:6px;">POST <code><?= e(asset_path('/api/webhooks/payments?provider=schoolpay&token=YOUR_TOKEN')) ?></code></div>
      <div class="muted" style="font-size:.85rem;margin-top:6px;">JSON: <code>{provider_reference,student_no|invoice_no,amount,currency,paid_at,event}</code>. Auto-posts to payments, updates invoice/fee account, dedupes by provider_reference.</div>
    </div>
    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
    <h3>Test Notification</h3>
    <?php if($canManage): ?><form method="post" class="grid"><?= CSRF::field() ?><input type="hidden" name="action" value="test_notification">
      <label>Channel<select class="input" name="channel"><option>sms</option><option>whatsapp</option><option>email</option></select></label>
      <label>To (phone/email)<input class="input" name="to" required></label>
      <label>Message<textarea class="input" name="message" rows="3">SchoolGate test notification.</textarea></label>
      <button class="btn secondary" type="submit">Send Test</button>
    </form><?php endif; ?>
    <div class="empty" style="margin-top:12px;"><strong>Webhook contract</strong><div class="muted" style="font-size:.85rem;margin-top:6px;">POST JSON: <code>{channel,to,message,meta,ts}</code>. Header: <code>X-SchoolGate-Signature: hmac_sha256(body,secret)</code> when secret set. Return JSON <code>{ok:true,id/ref}</code>.</div></div>
  </div>
</div>





<?php $recentNotifs=[]; $recentWebhooks=[]; if($pdo){ if(DB::hasTable('notifications')){ $st=$pdo->prepare('SELECT * FROM notifications WHERE school_id=? ORDER BY id DESC LIMIT 20'); $st->execute([(int)($user['school_id']??1)]); $recentNotifs=$st->fetchAll(); } if(DB::hasTable('webhook_events')){ $st=$pdo->prepare('SELECT * FROM webhook_events WHERE school_id=? ORDER BY id DESC LIMIT 20'); $st->execute([(int)($user['school_id']??1)]); $recentWebhooks=$st->fetchAll(); } } ?>
<div class="grid grid-2">
  <div class="card"><h3>Recent Notifications / Retries</h3>
    <table class="compact"><thead><tr><th>Time</th><th>Ch</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($recentNotifs as $n): ?><tr><td><?= e($n['created_at']) ?></td><td><?= e($n['channel']) ?></td><td><?= e($n['recipient_contact']?:'-') ?></td><td><span class="badge <?= $n['status']==='sent'?'ok':'warn' ?>"><?= e($n['status']) ?></span></td><td><?php if($canManage && $n['status']!=='sent'): ?><form method="post" style="display:inline"><?= CSRF::field() ?><input type="hidden" name="action" value="retry_notification"><input type="hidden" name="notification_id" value="<?= e($n['id']) ?>"><button class="btn secondary" style="padding:2px 8px;font-size:.75rem;">Retry</button></form><?php endif; ?></td></tr><?php endforeach; if(!$recentNotifs): ?><tr><td colspan="5" class="muted">No notifications.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <div class="card"><h3>Recent Payment Webhook Events</h3>
    <table class="compact"><thead><tr><th>Time</th><th>Provider</th><th>Ref</th><th>Status</th></tr></thead><tbody>
    <?php foreach($recentWebhooks as $w): ?><tr><td><?= e($w['created_at']) ?></td><td><?= e($w['provider']) ?></td><td><a href="<?= e(asset_path('/webhook-event?id='.$w['id'])) ?>"><?= e($w['external_ref']?:'view') ?></a></td><td><span class="badge <?= in_array($w['status'],['posted','posted_unallocated'])?'ok':($w['status']==='duplicate'?'':'warn') ?>"><?= e($w['status']) ?></span></td></tr><?php endforeach; if(!$recentWebhooks): ?><tr><td colspan="4" class="muted">No webhook events.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>



