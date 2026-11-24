<?php
RBAC::require('dashboard.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_parent_user') {
            $guardianId = (int)$_POST['guardian_id'];
            $email = strtolower(trim((string)$_POST['email']));
            $name = trim((string)$_POST['name']);
            $pass = (string)$_POST['password'];
            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='parent' LIMIT 1")->fetchColumn();
            if (!$roleId) throw new RuntimeException('Parent role missing.');
            if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<6) throw new RuntimeException('Valid email and password (6+) required.');
            $stmt=$pdo->prepare('INSERT INTO users (school_id,role_id,name,email,password_hash,status) VALUES (?,?,?,?,?,\"active\") ON DUPLICATE KEY UPDATE name=VALUES(name), role_id=VALUES(role_id), password_hash=VALUES(password_hash), status="active"');
            $stmt->execute([$schoolId,$roleId,$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
            $uidStmt=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1'); $uidStmt->execute([$email]); $uid=(int)$uidStmt->fetchColumn();
            $pdo->prepare('INSERT INTO parent_users (user_id,guardian_id) VALUES (?,?) ON DUPLICATE KEY UPDATE guardian_id=VALUES(guardian_id)')->execute([$uid,$guardianId ?: null]);
            Audit::log('parent_user.create','user',$uid,null,['guardian_id'=>$guardianId,'email'=>$email]);
            $message = 'Parent user account created/updated and linked.';
        }
    } catch(Throwable $e) { $error=$e->getMessage(); }
}

$guardians=[]; $parents=[]; $reports=['admissions'=>[],'attendance'=>[],'finance'=>[],'pickups'=>[]];
if ($pdo) {
    $st=$pdo->prepare('SELECT g.*, GROUP_CONCAT(CONCAT(s.student_no," ",s.first_name," ",s.last_name) SEPARATOR ", ") children FROM guardians g LEFT JOIN student_guardians sg ON sg.guardian_id=g.id LEFT JOIN students s ON s.id=sg.student_id WHERE g.school_id=? GROUP BY g.id ORDER BY g.id DESC LIMIT 100');
    $st->execute([$schoolId]); $guardians=$st->fetchAll();
    $st=$pdo->prepare("SELECT u.id,u.name,u.email,u.status,g.first_name gf,g.last_name gl FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN parent_users pu ON pu.user_id=u.id LEFT JOIN guardians g ON g.id=pu.guardian_id WHERE u.school_id=? AND r.name='parent' ORDER BY u.id DESC LIMIT 100");
    $st->execute([$schoolId]); $parents=$st->fetchAll();
    $st=$pdo->prepare("SELECT status, COUNT(*) c FROM admissions WHERE school_id=? GROUP BY status"); $st->execute([$schoolId]); $reports['admissions']=$st->fetchAll();
    $st=$pdo->prepare("SELECT ae.status, COUNT(*) c FROM attendance_entries ae JOIN attendance_sessions s ON s.id=ae.session_id WHERE s.school_id=? AND s.session_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY ae.status"); $st->execute([$schoolId]); $reports['attendance']=$st->fetchAll();
    $st=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) invoiced, COALESCE(SUM(paid_amount),0) paid, COALESCE(SUM(balance_amount),0) balance FROM invoices WHERE school_id=?"); $st->execute([$schoolId]); $reports['finance']=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st=$pdo->prepare("SELECT status, COUNT(*) c FROM pickup_logs WHERE school_id=? AND checked_out_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY status"); $st->execute([$schoolId]); $reports['pickups']=$st->fetchAll();
}
?>
<div class="card">
  <div class="section-title"><h2>Reports & Audit</h2><span class="badge warn">Phase 7</span></div>
  <p class="muted">Operational summaries, parent account linking, and audit visibility.</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-4">
  <div class="card stat"><div class="label">Invoiced (all)</div><div class="value" style="font-size:1.2rem;">UGX <?= e(number_format((float)($reports['finance']['invoiced']??0),2)) ?></div></div>
  <div class="card stat"><div class="label">Collected (all)</div><div class="value" style="font-size:1.2rem;">UGX <?= e(number_format((float)($reports['finance']['paid']??0),2)) ?></div></div>
  <div class="card stat"><div class="label">Outstanding</div><div class="value" style="font-size:1.2rem;">UGX <?= e(number_format((float)($reports['finance']['balance']??0),2)) ?></div></div>
  <div class="card stat"><div class="label">Pickup logs (30d)</div><div class="value"><?php $pc=0; foreach($reports['pickups'] as $r){$pc+=(int)$r['c'];} echo e((string)$pc); ?></div></div>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Admissions Funnel</h3>
    <table class="compact"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody><?php foreach($reports['admissions'] as $r): ?><tr><td><?= e($r['status']) ?></td><td><?= e($r['c']) ?></td></tr><?php endforeach; if(!$reports['admissions']): ?><tr><td colspan="2" class="muted">No admissions yet.</td></tr><?php endif; ?></tbody></table>
    <h3 style="margin-top:16px;">Attendance (30 days)</h3>
    <table class="compact"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody><?php foreach($reports['attendance'] as $r): ?><tr><td><?= e($r['status']) ?></td><td><?= e($r['c']) ?></td></tr><?php endforeach; if(!$reports['attendance']): ?><tr><td colspan="2" class="muted">No attendance yet.</td></tr><?php endif; ?></tbody></table>
  </div>
  <div class="card">
    <h3>Link Parent Account</h3>
    <?php if($canManage): ?>
    <form method="post" class="grid">
      <?= CSRF::field() ?>
      <input type="hidden" name="action" value="create_parent_user">
      <label>Guardian<select class="input" name="guardian_id" required><option value="">Select guardian</option><?php foreach($guardians as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['first_name'].' '.$g['last_name'].' ('.$g['phone'].')') ?></option><?php endforeach; ?></select></label>
      <label>Parent name<input class="input" name="name" required></label>
      <label>Email<input class="input" name="email" type="email" required></label>
      <label>Password<input class="input" name="password" type="password" minlength="6" required></label>
      <button class="btn" type="submit">Create/Link Parent User</button>
    </form>
    <?php endif; ?>
    <h3 style="margin-top:16px;">Parent Users</h3>
    <table class="compact"><thead><tr><th>Name</th><th>Email</th><th>Guardian</th></tr></thead><tbody><?php foreach($parents as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= e($p['email']) ?></td><td><?= e(trim(($p['gf']??'').' '.($p['gl']??'')) ?: '-') ?></td></tr><?php endforeach; if(!$parents): ?><tr><td colspan="3" class="muted">No parent users yet.</td></tr><?php endif; ?></tbody></table>
  </div>
</div>
