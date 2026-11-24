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
              'sms'=>['driver'=>$_POST['sms_driver']??'log','webhook_url'=>trim($_POST['sms_webhook_url']??''),'webhook_secret'=>trim($_POST['sms_webhook_secret']??'')],
              'whatsapp'=>['driver'=>$_POST['whatsapp_driver']??'log','webhook_url'=>trim($_POST['whatsapp_webhook_url']??''),'webhook_secret'=>trim($_POST['whatsapp_webhook_secret']??'')],
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
            $res = Notifier::providerFor($ch)->send($ch,$to,$msg,['title'=>'Test notification']);
            $pdo->prepare('UPDATE notifications SET status=?, provider_ref=?, sent_at=? WHERE id=?')->execute([$res['ok']?'sent':'failed',$res['ref']??($res['error']??null),$res['ok']?date('Y-m-d H:i:s'):null,$id]);
            $message = $res['ok'] ? 'Test sent. Ref: '.($res['ref']??'ok') : 'Test failed: '.($res['error']??'unknown');
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
          <label>Driver<select class="input" name="<?= $k ?>_driver"><option value="log" <?= ($c['driver']??'')==='log'?'selected':'' ?>>log</option><option value="webhook" <?= ($c['driver']??'')==='webhook'?'selected':'' ?>>webhook</option><?php if($k==='email'): ?><option value="mail" <?= ($c['driver']??'')==='mail'?'selected':'' ?>>mail()</option><?php endif; ?></select></label>
          <?php if($k==='email'): ?><label>From<input class="input" name="email_from" value="<?= e($c['from']??'') ?>"></label><?php endif; ?>
          <label>Webhook URL<input class="input" name="<?= $k ?>_webhook_url" value="<?= e($c['webhook_url']??'') ?>"></label>
          <label>Webhook secret<input class="input" name="<?= $k ?>_webhook_secret" value="<?= e($c['webhook_secret']??'') ?>"></label>
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



