<?php
RBAC::require('students.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$isAdmin = in_array(Auth::user()['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_student' && $isAdmin) {
            $studentNo = trim((string)($_POST['student_no'] ?? ''));
            if ($studentNo === '') $studentNo = 'STU-' . date('Y') . '-' . str_pad((string)(random_int(1,99999)), 5, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare('INSERT INTO students (school_id,student_no,admission_no,first_name,last_name,other_name,gender,dob,class_id,stream_id,status,medical_notes,allergies,emergency_contact_name,emergency_contact_phone,notes,admitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $schoolId, $studentNo, trim($_POST['admission_no'] ?? '') ?: null,
                trim($_POST['first_name'] ?? ''), trim($_POST['last_name'] ?? ''), trim($_POST['other_name'] ?? '') ?: null,
                trim($_POST['gender'] ?? '') ?: null, $_POST['dob'] ?: null,
                $_POST['class_id'] ?: null, $_POST['stream_id'] ?: null, $_POST['status'] ?? 'active',
                trim($_POST['medical_notes'] ?? '') ?: null, trim($_POST['allergies'] ?? '') ?: null,
                trim($_POST['emergency_contact_name'] ?? '') ?: null, trim($_POST['emergency_contact_phone'] ?? '') ?: null,
                trim($_POST['notes'] ?? '') ?: null, $_POST['admitted_at'] ?: date('Y-m-d')
            ]);
            $studentId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO fee_accounts (school_id,student_id,currency,status) VALUES (?,?,?,?)')->execute([$schoolId,$studentId,'UGX','active']);
            Audit::log('student.create','student',$studentId,null,$_POST);
            $message = "Student created ($studentNo). Fee account initialized.";
        }
        if ($action === 'add_guardian' && $isAdmin) {
            $stmt = $pdo->prepare('INSERT INTO guardians (school_id,first_name,last_name,relationship,phone,email,address,id_number) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId, trim($_POST['first_name']), trim($_POST['last_name']), trim($_POST['relationship'] ?: null), trim($_POST['phone'] ?: null), trim($_POST['email'] ?: null), trim($_POST['address'] ?: null), trim($_POST['id_number'] ?: null)]);
            $gid = (int)$pdo->lastInsertId();
            $sid = (int)$_POST['student_id'];
            $pdo->prepare('INSERT IGNORE INTO student_guardians (student_id,guardian_id,is_primary,can_pickup) VALUES (?,?,?,?)')->execute([$sid,$gid,isset($_POST['is_primary'])?1:0,isset($_POST['can_pickup'])?1:0]);
            Audit::log('guardian.create_link','guardian',$gid,null,$_POST);
            $message = 'Guardian added and linked to student.';
        }
        if ($action === 'add_pickup' && $isAdmin) {
            $code = trim($_POST['pickup_code'] ?? '');
            if ($code === '') $code = strtoupper(substr(bin2hex(random_bytes(3)),0,6));
            $stmt = $pdo->prepare('INSERT INTO authorized_pickups (school_id,student_id,name,relationship,phone,id_number,pickup_code,is_active) VALUES (?,?,?,?,?,?,?,1)');
            $stmt->execute([$schoolId,(int)$_POST['student_id'],trim($_POST['name']),trim($_POST['relationship']?:null),trim($_POST['phone']?:null),trim($_POST['id_number']?:null),$code]);
            Audit::log('pickup_authorization.create','authorized_pickup',$pdo->lastInsertId(),null,$_POST);
            $message = "Authorized pickup person added. Code: $code";
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$classes = $streams = $students = [];
if ($pdo && DB::hasTable('students')) {
    $classes = $pdo->prepare('SELECT * FROM classes WHERE school_id=? ORDER BY name'); $classes->execute([$schoolId]); $classes = $classes->fetchAll();
    $streams = $pdo->query('SELECT s.*, c.name class_name FROM streams s JOIN classes c ON c.id=s.class_id ORDER BY c.name,s.name')->fetchAll();
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT s.*, c.name class_name, st.name stream_name, fa.balance, fa.currency FROM students s LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN streams st ON st.id=s.stream_id LEFT JOIN fee_accounts fa ON fa.student_id=s.id WHERE s.school_id=?';
    $params = [$schoolId];
    if ($q !== '') { $sql .= ' AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_no LIKE ? OR s.admission_no LIKE ?)'; $like="%$q%"; array_push($params,$like,$like,$like,$like); }
    $sql .= ' ORDER BY s.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $students = $stmt->fetchAll();
}
?>
<div class="card">
  <div class="section-title"><h2>Student Registry</h2><span class="badge <?= $isAdmin?'ok':'warn' ?>"><?= $isAdmin?'Manage':'Read only' ?></span></div>
  <p class="muted">One student ID across admissions, attendance, pickup, academics, fees and transport.</p>
  <?php if ($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Add Student</h3>
    <?php if ($isAdmin): ?>
    <form method="post"><?= CSRF::field() ?> class="grid">
      <input type="hidden" name="action" value="add_student">
      <div class="grid grid-2"><label>Student No<input class="input" name="student_no" placeholder="auto if blank"></label><label>Admission No<input class="input" name="admission_no"></label></div>
      <div class="grid grid-2"><label>First name<input class="input" name="first_name" required></label><label>Last name<input class="input" name="last_name" required></label></div>
      <div class="grid grid-2"><label>Other name<input class="input" name="other_name"></label><label>Gender<select class="input" name="gender"><option value="">Select</option><option>Male</option><option>Female</option></select></label></div>
      <div class="grid grid-2"><label>DOB<input class="input" type="date" name="dob"></label><label>Admitted<input class="input" type="date" name="admitted_at" value="<?= e(date('Y-m-d')) ?>"></label></div>
      <div class="grid grid-2"><label>Class<select class="input" name="class_id"><option value="">Select</option><?php foreach($classes as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <label>Stream<select class="input" name="stream_id"><option value="">Select</option><?php foreach($streams as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['class_name'].' - '.$s['name']) ?></option><?php endforeach; ?></select></label></div>
      <label>Status<select class="input" name="status"><option value="active">active</option><option value="inactive">inactive</option><option value="graduated">graduated</option><option value="withdrawn">withdrawn</option></select></label>
      <label>Medical notes<textarea class="input" name="medical_notes" rows="2"></textarea></label>
      <label>Allergies<textarea class="input" name="allergies" rows="2"></textarea></label>
      <div class="grid grid-2"><label>Emergency contact<input class="input" name="emergency_contact_name"></label><label>Emergency phone<input class="input" name="emergency_contact_phone"></label></div>
      <label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
      <button class="btn" type="submit">Create Student</button>
    </form>
    <?php else: ?><div class="empty">Only admins can create students.</div><?php endif; ?>
  </div>
  <div class="card">
    <h3>Guardian & Authorized Pickup</h3>
    <?php if ($isAdmin): ?>
    <form method="post"><?= CSRF::field() ?> class="grid" style="margin-bottom:14px;">
      <input type="hidden" name="action" value="add_guardian">
      <label>Student<select class="input" name="student_id" required><option value="">Select student</option><?php foreach($students as $st): ?><option value="<?= e($st['id']) ?>"><?= e($st['student_no'].' - '.$st['first_name'].' '.$st['last_name']) ?></option><?php endforeach; ?></select></label>
      <div class="grid grid-2"><label>Guardian first name<input class="input" name="first_name" required></label><label>Last name<input class="input" name="last_name" required></label></div>
      <div class="grid grid-2"><label>Relationship<input class="input" name="relationship"></label><label>Phone<input class="input" name="phone"></label></div>
      <div class="grid grid-2"><label>Email<input class="input" name="email"></label><label>ID number<input class="input" name="id_number"></label></div>
      <label><input type="checkbox" name="is_primary"> Primary guardian</label>
      <label><input type="checkbox" name="can_pickup" checked> Can pick up child</label>
      <button class="btn secondary" type="submit">Add Guardian</button>
    </form>
    <form method="post"><?= CSRF::field() ?> class="grid">
      <input type="hidden" name="action" value="add_pickup">
      <label>Student<select class="input" name="student_id" required><option value="">Select student</option><?php foreach($students as $st): ?><option value="<?= e($st['id']) ?>"><?= e($st['student_no'].' - '.$st['first_name'].' '.$st['last_name']) ?></option><?php endforeach; ?></select></label>
      <div class="grid grid-2"><label>Pickup person name<input class="input" name="name" required></label><label>Relationship<input class="input" name="relationship"></label></div>
      <div class="grid grid-2"><label>Phone<input class="input" name="phone"></label><label>ID number<input class="input" name="id_number"></label></div>
      <label>Pickup code<input class="input" name="pickup_code" placeholder="auto if blank"></label>
      <button class="btn secondary" type="submit">Add Authorized Pickup</button>
    </form>
    <?php else: ?><div class="empty">Read-only access.</div><?php endif; ?>
  </div>
</div>
<div class="card">
  <div class="section-title"><h3>Students</h3><form method="get" style="display:flex;gap:8px;"><input class="input" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name/no"><button class="btn secondary" type="submit">Search</button></form></div>
  <table class="compact"><thead><tr><th>Student No</th><th>Name</th><th>Class</th><th>Status</th><th>Fee Balance</th><th>Emergency</th></tr></thead><tbody>
    <?php foreach($students as $st): ?><tr>
      <td><?= e($st['student_no']) ?></td>
      <td><?= e($st['first_name'].' '.$st['last_name']) ?><div class="muted" style="font-size:.78rem;"><?= e($st['admission_no'] ?: '') ?></div></td>
      <td><?= e(($st['class_name'] ?: '-') . ($st['stream_name'] ? ' / '.$st['stream_name'] : '')) ?></td>
      <td><span class="badge <?= $st['status']==='active'?'ok':'warn' ?>"><?= e($st['status']) ?></span></td>
      <td><?= e(($st['currency'] ?: 'UGX').' '.number_format((float)($st['balance'] ?? 0),2)) ?></td>
      <td><?= e(($st['emergency_contact_name'] ?: '-') . ($st['emergency_contact_phone'] ? ' ('.$st['emergency_contact_phone'].')' : '')) ?></td>
    </tr><?php endforeach; if(!$students): ?><tr><td colspan="6" class="muted">No students found.</td></tr><?php endif; ?>
  </tbody></table>
</div>





