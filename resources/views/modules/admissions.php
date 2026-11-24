<?php
RBAC::require('students.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$isAdmin = in_array(Auth::user()['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $isAdmin) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_admission') {
            $appNo = trim($_POST['application_no'] ?? '');
            if ($appNo === '') $appNo = 'APP-' . date('Y') . '-' . str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT);
            $stmt = $pdo->prepare('INSERT INTO admissions (school_id,application_no,first_name,last_name,other_name,gender,dob,class_applied_id,guardian_name,guardian_phone,guardian_email,address,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,$appNo,trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['other_name']?:null),trim($_POST['gender']?:null),$_POST['dob']?:null,$_POST['class_applied_id']?:null,trim($_POST['guardian_name']?:null),trim($_POST['guardian_phone']?:null),trim($_POST['guardian_email']?:null),trim($_POST['address']?:null),$_POST['status']??'enquiry',trim($_POST['notes']?:null)]);
            Audit::log('admission.create','admission',$pdo->lastInsertId(),null,$_POST);
            $message = "Admission/application created: $appNo";
        }
        if ($action === 'status') {
            $pdo->prepare('UPDATE admissions SET status=?, reviewed_at=NOW() WHERE id=? AND school_id=?')->execute([$_POST['status'], (int)$_POST['id'], $schoolId]);
            Audit::log('admission.status','admission',(int)$_POST['id'],null,['status'=>$_POST['status']]);
            $message = 'Admission status updated.';
        }
        if ($action === 'enroll') {
            $id = (int)$_POST['id'];
            $a = $pdo->prepare('SELECT * FROM admissions WHERE id=? AND school_id=?'); $a->execute([$id,$schoolId]); $a = $a->fetch();
            if (!$a) throw new RuntimeException('Application not found.');
            if ($a['student_id']) throw new RuntimeException('Already enrolled.');
            $studentNo = 'STU-' . date('Y') . '-' . str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT);
            $stmt = $pdo->prepare('INSERT INTO students (school_id,student_no,admission_no,first_name,last_name,other_name,gender,dob,class_id,status,admitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,$studentNo,$a['application_no'],$a['first_name'],$a['last_name'],$a['other_name'],$a['gender'],$a['dob'],$a['class_applied_id'],'active',date('Y-m-d')]);
            $studentId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO fee_accounts (school_id,student_id,currency,status) VALUES (?,?,?,?)')->execute([$schoolId,$studentId,'UGX','active']);
            if ($a['guardian_name']) {
                $parts = preg_split('/\s+/', trim($a['guardian_name']), 2);
                $g = $pdo->prepare('INSERT INTO guardians (school_id,first_name,last_name,phone,email) VALUES (?,?,?,?,?)');
                $g->execute([$schoolId,$parts[0] ?? $a['guardian_name'],$parts[1] ?? '',$a['guardian_phone'],$a['guardian_email']]);
                $pdo->prepare('INSERT IGNORE INTO student_guardians (student_id,guardian_id,is_primary,can_pickup) VALUES (?,?,1,1)')->execute([$studentId,(int)$pdo->lastInsertId()]);
            }
            $pdo->prepare('UPDATE admissions SET status="enrolled", student_id=?, reviewed_at=NOW() WHERE id=?')->execute([$studentId,$id]);
            Audit::log('admission.enroll','admission',$id,null,['student_id'=>$studentId,'student_no'=>$studentNo]);
            $message = "Enrolled as $studentNo. Student, fee account and guardian link created.";
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$classes = $admissions = [];
if ($pdo && DB::hasTable('admissions')) {
    $classes = $pdo->prepare('SELECT * FROM classes WHERE school_id=? ORDER BY name'); $classes->execute([$schoolId]); $classes = $classes->fetchAll();
    $admissions = $pdo->prepare('SELECT a.*, c.name class_name, s.student_no FROM admissions a LEFT JOIN classes c ON c.id=a.class_applied_id LEFT JOIN students s ON s.id=a.student_id WHERE a.school_id=? ORDER BY a.created_at DESC LIMIT 100');
    $admissions->execute([$schoolId]); $admissions = $admissions->fetchAll();
}
?>
<div class="card">
  <div class="section-title"><h2>Admissions / Enrollment</h2><span class="badge warn">Phase 2</span></div>
  <p class="muted">Enquiry → application → approval → enrolled student. Enrollment creates student record, fee account, and guardian link.</p>
  <?php if ($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>New Application</h3>
    <?php if ($isAdmin): ?>
    <form method="post"><?= CSRF::field() ?> class="grid">
      <input type="hidden" name="action" value="add_admission">
      <label>Application No<input class="input" name="application_no" placeholder="auto if blank"></label>
      <div class="grid grid-2"><label>First name<input class="input" name="first_name" required></label><label>Last name<input class="input" name="last_name" required></label></div>
      <div class="grid grid-2"><label>Other name<input class="input" name="other_name"></label><label>Gender<select class="input" name="gender"><option value="">Select</option><option>Male</option><option>Female</option></select></label></div>
      <div class="grid grid-2"><label>DOB<input class="input" type="date" name="dob"></label><label>Class applied<select class="input" name="class_applied_id"><option value="">Select</option><?php foreach($classes as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label></div>
      <label>Guardian name<input class="input" name="guardian_name"></label>
      <div class="grid grid-2"><label>Guardian phone<input class="input" name="guardian_phone"></label><label>Guardian email<input class="input" name="guardian_email"></label></div>
      <label>Address<textarea class="input" name="address" rows="2"></textarea></label>
      <label>Status<select class="input" name="status"><option value="enquiry">enquiry</option><option value="applied">applied</option><option value="interview">interview</option><option value="approved">approved</option><option value="waitlist">waitlist</option><option value="rejected">rejected</option></select></label>
      <label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
      <button class="btn" type="submit">Save Application</button>
    </form>
    <?php else: ?><div class="empty">Read-only access.</div><?php endif; ?>
  </div>
  <div class="card">
    <h3>Applications</h3>
    <table class="compact"><thead><tr><th>App No</th><th>Applicant</th><th>Class</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php foreach($admissions as $a): ?><tr>
        <td><?= e($a['application_no']) ?></td>
        <td><?= e($a['first_name'].' '.$a['last_name']) ?><div class="muted" style="font-size:.78rem;"><?= e($a['guardian_phone'] ?: '') ?></div></td>
        <td><?= e($a['class_name'] ?: '-') ?></td>
        <td><span class="badge <?= in_array($a['status'],['approved','enrolled'])?'ok':'warn' ?>"><?= e($a['status']) ?></span><?php if($a['student_no']): ?><div class="muted" style="font-size:.75rem;"><?= e($a['student_no']) ?></div><?php endif; ?></td>
        <td>
          <?php if ($isAdmin && $a['status'] !== 'enrolled'): ?>
            <form method="post"><?= CSRF::field() ?> style="display:inline"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= e($a['id']) ?>"><input type="hidden" name="status" value="approved"><button class="btn secondary" type="submit">Approve</button></form>
            <form method="post"><?= CSRF::field() ?> style="display:inline" onsubmit="return confirm('Enroll this applicant? Creates student, fee account, guardian link.')"><input type="hidden" name="action" value="enroll"><input type="hidden" name="id" value="<?= e($a['id']) ?>"><button class="btn" type="submit">Enroll</button></form>
          <?php elseif($a['status']==='enrolled'): ?><span class="badge ok">Enrolled</span><?php endif; ?>
        </td>
      </tr><?php endforeach; if(!$admissions): ?><tr><td colspan="5" class="muted">No applications yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>



