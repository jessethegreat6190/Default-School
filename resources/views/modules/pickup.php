<?php
RBAC::require('pickup.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','teacher','gate_staff'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'checkout') {
            $studentId = (int)$_POST['student_id'];
            $authId = $_POST['authorized_pickup_id'] !== '' ? (int)$_POST['authorized_pickup_id'] : null;
            $collector = trim((string)$_POST['collector_name']);
            $method = $_POST['verification_method'] ?? 'manual';
            $code = trim((string)($_POST['verification_code'] ?? ''));
            $otp = trim((string)($_POST['otp_code'] ?? ''));
            $status = 'verified';

            $authorized = null;
            if ($authId) {
                $st = $pdo->prepare('SELECT * FROM authorized_pickups WHERE id=? AND school_id=? AND student_id=? AND is_active=1');
                $st->execute([$authId,$schoolId,$studentId]); $authorized = $st->fetch();
                if ($authorized) {
                    if ($collector === '') $collector = $authorized['name'];
                    if ($code !== '' && $authorized['pickup_code'] && strcasecmp($code, $authorized['pickup_code']) !== 0) {
                        throw new RuntimeException('Pickup code does not match authorized person.');
                    }
                }
            }
            if ($collector === '') throw new RuntimeException('Collector name is required.');
            if ($method === 'otp_override' && $otp === '') throw new RuntimeException('OTP code required for override.');
            if (!$authId && $method !== 'otp_override') {
                $status = 'blocked'; // unknown person without override
            }
            if ($status === 'blocked') {
                Audit::log('pickup.blocked','student',$studentId,null,$_POST);
                throw new RuntimeException('Pickup blocked: person not in authorized list. Use OTP override only with parent approval.');
            }
            $stmt = $pdo->prepare('INSERT INTO pickup_logs (school_id,student_id,authorized_pickup_id,collector_name,collector_phone,collector_id_number,relationship,verification_method,verification_code,otp_code,otp_approved_at,status,notes,checked_out_by,checked_out_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$schoolId,$studentId,$authId,$collector,trim($_POST['collector_phone']?:null),trim($_POST['collector_id_number']?:null),trim($_POST['relationship']?:null),$method,$code?:null,$otp?:null,$method==='otp_override'?date('Y-m-d H:i:s'):null,$status,trim($_POST['notes']?:null),$user['id']]);
            $logId = (int)$pdo->lastInsertId();
            Audit::log('pickup.checkout','pickup_log',$logId,null,['student_id'=>$studentId,'collector'=>$collector,'method'=>$method]);
            $sname = '';
            $ssn=$pdo->prepare('SELECT first_name,last_name,student_no FROM students WHERE id=?'); $ssn->execute([$studentId]); $sr=$ssn->fetch();
            if($sr) $sname = $sr['student_no'].' '.$sr['first_name'].' '.$sr['last_name'];
            $nmsg = "Pickup verified: $sname was checked out by $collector via $method at " . date('Y-m-d H:i') . ".";
            $sent = Notifier::notifyStudentGuardians($schoolId,$studentId,'pickup','Pickup verified',$nmsg,'pickup_log',$logId,$user['id']);
            $message = "Pickup verified and logged. Notifications queued/sent: $sent.";
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$students=[]; $authPickups=[]; $logs=[];
if ($pdo) {
    $st=$pdo->prepare('SELECT id,student_no,first_name,last_name,class_id FROM students WHERE school_id=? AND status="active" ORDER BY first_name,last_name'); $st->execute([$schoolId]); $students=$st->fetchAll();
    $ap=$pdo->prepare('SELECT * FROM authorized_pickups WHERE school_id=? AND is_active=1 ORDER BY student_id,name'); $ap->execute([$schoolId]);
    foreach($ap->fetchAll() as $r){ $authPickups[$r['student_id']][]=$r; }
    $lg=$pdo->prepare('SELECT p.*, s.student_no,s.first_name,s.last_name, u.name checked_out_by_name FROM pickup_logs p JOIN students s ON s.id=p.student_id LEFT JOIN users u ON u.id=p.checked_out_by WHERE p.school_id=? ORDER BY p.checked_out_at DESC LIMIT 50');
    $lg->execute([$schoolId]); $logs=$lg->fetchAll();
}
?>
<div class="card">
  <div class="section-title"><h2>Safe Pickup / Dismissal</h2><span class="badge ok">Flagship</span></div>
  <p class="muted">Verify authorized collector before releasing a child. Unknown person is blocked unless parent OTP override is recorded.</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Checkout Verification</h3>
    <?php if($canManage): ?>
    <form method="post"><?= CSRF::field() ?> class="grid">
      <input type="hidden" name="action" value="checkout">
      <label>Student<select class="input" name="student_id" id="pickupStudent" required><option value="">Select student</option><?php foreach($students as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['student_no'].' - '.$s['first_name'].' '.$s['last_name']) ?></option><?php endforeach; ?></select></label>
      <label>Authorized person<select class="input" name="authorized_pickup_id" id="pickupAuth"><option value="">-- unknown / not selected --</option></select></label>
      <label>Collector name<input class="input" name="collector_name" id="collectorName" placeholder="Auto-filled if authorized selected"></label>
      <div class="grid grid-2"><label>Collector phone<input class="input" name="collector_phone" id="collectorPhone"></label><label>ID number<input class="input" name="collector_id_number" id="collectorId"></label></div>
      <div class="grid grid-2"><label>Relationship<input class="input" name="relationship" id="collectorRel"></label><label>Verification method<select class="input" name="verification_method"><option value="qr">qr</option><option value="pickup_card">pickup_card</option><option value="manual_code">manual_code</option><option value="manual" selected>manual</option><option value="otp_override">otp_override</option></select></label></div>
      <div class="grid grid-2"><label>Pickup code<input class="input" name="verification_code"></label><label>Parent OTP (override only)<input class="input" name="otp_code"></label></div>
      <label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
      <button class="btn" type="submit">Verify & Check Out</button>
    </form>
    <script>window.SCHOOLGATE_PICKUPS = <?= json_encode($authPickups, JSON_UNESCAPED_SLASHES) ?>;</script>
    <?php else: ?><div class="empty">Read-only access.</div><?php endif; ?>
  </div>
  <div class="card">
    <h3>Recent Checkout Log</h3>
    <table class="compact"><thead><tr><th>Time</th><th>Student</th><th>Collector</th><th>Method</th><th>Status</th></tr></thead><tbody>
      <?php foreach($logs as $l): ?><tr>
        <td><?= e($l['checked_out_at']) ?></td>
        <td><?= e($l['student_no'].' '.$l['first_name']) ?></td>
        <td><?= e($l['collector_name']) ?><div class="muted" style="font-size:.75rem;"><?= e($l['relationship'] ?: '') ?></div></td>
        <td><?= e($l['verification_method']) ?></td>
        <td><span class="badge <?= $l['status']==='verified'?'ok':'danger' ?>"><?= e($l['status']) ?></span></td>
      </tr><?php endforeach; if(!$logs): ?><tr><td colspan="5" class="muted">No pickup logs yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>




