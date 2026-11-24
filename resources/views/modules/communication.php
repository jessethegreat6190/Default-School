<?php
RBAC::require('dashboard.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'announce') {
            $stmt=$pdo->prepare('INSERT INTO announcements (school_id,title,body,audience,status,published_at,created_by) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,trim($_POST['title']),trim($_POST['body']),$_POST['audience']??'all','published',date('Y-m-d H:i:s'),$user['id']]);
            Audit::log('announcement.create','announcement',$pdo->lastInsertId(),null,$_POST);
            $message='Announcement published.';
        }
        if ($action === 'queue_notification') {
            $stmt=$pdo->prepare('INSERT INTO notifications (school_id,recipient_type,recipient_contact,channel,category,title,message,status,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,$_POST['recipient_type']??'parent',trim($_POST['recipient_contact']?:null),$_POST['channel']??'sms',$_POST['category']??'general',trim($_POST['title']?:null),trim($_POST['message']),'queued',$user['id']]);
            Audit::log('notification.queue','notification',$pdo->lastInsertId(),null,$_POST);
            $message='Notification queued (delivery provider integration pending).';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$announcements=[]; $notifications=[];
if ($pdo && DB::hasTable('announcements')) {
    $st=$pdo->prepare('SELECT a.*, u.name author FROM announcements a LEFT JOIN users u ON u.id=a.created_by WHERE a.school_id=? ORDER BY a.published_at DESC LIMIT 20'); $st->execute([$schoolId]); $announcements=$st->fetchAll();
    $st=$pdo->prepare('SELECT n.*, u.name author FROM notifications n LEFT JOIN users u ON u.id=n.created_by WHERE n.school_id=? ORDER BY n.id DESC LIMIT 30'); $st->execute([$schoolId]); $notifications=$st->fetchAll();
}
?>
<div class="card">
  <div class="section-title"><h2>Parent Portal & Communication</h2><span class="badge warn">Phase 7</span></div>
  <p class="muted">Announcements, emergency broadcasts, and notification queue. SMS/WhatsApp provider delivery can be plugged in next (queue already captured).</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Publish Announcement / Broadcast</h3>
    <?php if($canManage): ?>
    <form method="post" class="grid"><?= CSRF::field() ?>
      <input type="hidden" name="action" value="announce">
      <label>Title<input class="input" name="title" required></label>
      <label>Audience<select class="input" name="audience"><option value="all">All</option><option value="parents">Parents</option><option value="teachers">Teachers</option><option value="class">Specific class (put in body for now)</option></select></label>
      <label>Message<textarea class="input" name="body" rows="4" required></textarea></label>
      <button class="btn" type="submit">Publish</button>
    </form>
    <?php endif; ?>
    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
    <h3>Queue Notification</h3>
    <?php if($canManage): ?>
    <form method="post" class="grid"><?= CSRF::field() ?>
      <input type="hidden" name="action" value="queue_notification">
      <div class="grid grid-2"><label>Channel<select class="input" name="channel"><option>sms</option><option>whatsapp</option><option>email</option><option>in_app</option></select></label><label>Category<select class="input" name="category"><option>general</option><option>pickup</option><option>absence</option><option>fee</option><option>emergency</option></select></label></div>
      <div class="grid grid-2"><label>Recipient type<select class="input" name="recipient_type"><option>parent</option><option>staff</option><option>all_parents</option></select></label><label>Contact<input class="input" name="recipient_contact" placeholder="phone/email"></label></div>
      <label>Title<input class="input" name="title"></label>
      <label>Message<textarea class="input" name="message" rows="3" required></textarea></label>
      <button class="btn secondary" type="submit">Queue Notification</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>Recent Announcements</h3>
    <table class="compact"><thead><tr><th>When</th><th>Title</th><th>Audience</th></tr></thead><tbody>
      <?php foreach($announcements as $a): ?><tr><td><?= e($a['published_at']) ?></td><td><?= e($a['title']) ?><div class="muted" style="font-size:.75rem;"><?= e(mb_strimwidth($a['body'],0,80,'...')) ?></div></td><td><?= e($a['audience']) ?></td></tr><?php endforeach; if(!$announcements): ?><tr><td colspan="3" class="muted">No announcements yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<div class="card">
  <h3>Notification Queue</h3>
  <table class="compact"><thead><tr><th>Created</th><th>Channel</th><th>Category</th><th>Recipient</th><th>Status</th><th>Message</th></tr></thead><tbody>
    <?php foreach($notifications as $n): ?><tr><td><?= e($n['created_at']) ?></td><td><?= e($n['channel']) ?></td><td><?= e($n['category']) ?></td><td><?= e($n['recipient_contact'] ?: $n['recipient_type']) ?></td><td><span class="badge <?= $n['status']==='sent'?'ok':'warn' ?>"><?= e($n['status']) ?></span></td><td><?= e(mb_strimwidth($n['message'],0,60,'...')) ?></td></tr><?php endforeach; if(!$notifications): ?><tr><td colspan="6" class="muted">No notifications queued.</td></tr><?php endif; ?>
  </tbody></table>
</div>

