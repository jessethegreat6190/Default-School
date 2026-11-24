<?php
RBAC::require('attendance.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user(); $isAdmin = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','teacher'], true);
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_attendance') {
            $classId = (int)$_POST['class_id']; $date = $_POST['session_date']; $streamId = $_POST['stream_id'] !== '' ? (int)$_POST['stream_id'] : null;
            $termId = $_POST['term_id'] !== '' ? (int)$_POST['term_id'] : null;
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM attendance_sessions WHERE school_id=? AND class_id=? AND session_date=? AND ((stream_id IS NULL AND ? IS NULL) OR stream_id=?) LIMIT 1');
            $stmt->execute([$schoolId,$classId,$date,$streamId,$streamId]); $sid = $stmt->fetchColumn();
            if ($sid) {
                $pdo->prepare('UPDATE attendance_sessions SET marked_by=?, term_id=?, updated_at=NOW() WHERE id=?')->execute([$user['id'],$termId,$sid]);
            } else {
                $pdo->prepare('INSERT INTO attendance_sessions (school_id,class_id,stream_id,session_date,term_id,marked_by,status) VALUES (?,?,?,?,?,?,?)')->execute([$schoolId,$classId,$streamId,$date,$termId,$user['id'],'open']);
                $sid = (int)$pdo->lastInsertId();
            }
            $statuses = $_POST['status'] ?? []; $notes = $_POST['note'] ?? [];
            $up = $pdo->prepare('INSERT INTO attendance_entries (session_id,student_id,status,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), note=VALUES(note), updated_at=NOW()');
            foreach ($statuses as $studentId => $status) {
                $note = trim((string)($notes[$studentId] ?? ''));
                $up->execute([$sid,(int)$studentId,$status,$note ?: null]);
            }
            $pdo->commit();
            Audit::log('attendance.save','attendance_session',$sid,null,['class_id'=>$classId,'date'=>$date,'count'=>count($statuses)]);
            $notified=0;
            foreach ($statuses as $studentId => $status) {
                if (!in_array($status,['absent','late'],true)) continue;
                $ssn=$pdo->prepare('SELECT first_name,last_name,student_no FROM students WHERE id=?'); $ssn->execute([(int)$studentId]); $sr=$ssn->fetch();
                $sname = $sr ? ($sr['student_no'].' '.$sr['first_name'].' '.$sr['last_name']) : 'Student';
                $nmsg = "Attendance alert: $sname marked $status on $date.";
                $notified += Notifier::notifyStudentGuardians($schoolId,(int)$studentId,'absence','Attendance alert',$nmsg,'attendance_session',$sid,$user['id']);
            }
            $message = "Attendance saved. Absence/late notifications: $notified.";
        }
        if ($action === 'add_timetable' && in_array($user['role_name'], ['super_admin','school_admin','head_teacher'], true)) {
            $stmt = $pdo->prepare('INSERT INTO timetable_slots (school_id,class_id,stream_id,subject_id,teacher_id,day_of_week,start_time,end_time,room) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,(int)$_POST['class_id'],$_POST['stream_id']!==''?(int)$_POST['stream_id']:null,$_POST['subject_id']!==''?(int)$_POST['subject_id']:null,$_POST['teacher_id']!==''?(int)$_POST['teacher_id']:null,(int)$_POST['day_of_week'],$_POST['start_time'],$_POST['end_time'],trim($_POST['room']?:null)]);
            Audit::log('timetable.create','timetable_slot',$pdo->lastInsertId(),null,$_POST); $message='Timetable slot added.';
        }
        if ($action === 'delete_timetable' && in_array($user['role_name'], ['super_admin','school_admin','head_teacher'], true)) {
            $pdo->prepare('DELETE FROM timetable_slots WHERE id=? AND school_id=?')->execute([(int)$_POST['id'],$schoolId]);
            Audit::log('timetable.delete','timetable_slot',(int)$_POST['id']); $message='Timetable slot deleted.';
        }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
}

$classes=$streams=$terms=$subjects=$teachers=$students=$entries=$slots=[]; $selectedSession=null;
$selectedClass=(int)($_GET['class_id'] ?? 0); $selectedStream=$_GET['stream_id'] ?? ''; $selectedDate=$_GET['session_date'] ?? date('Y-m-d'); $selectedTerm=$_GET['term_id'] ?? '';
if ($pdo) {
    $st=$pdo->prepare('SELECT * FROM classes WHERE school_id=? ORDER BY name'); $st->execute([$schoolId]); $classes=$st->fetchAll();
    $streams=$pdo->query('SELECT s.*, c.name class_name FROM streams s JOIN classes c ON c.id=s.class_id ORDER BY c.name,s.name')->fetchAll();
    $terms=$pdo->query('SELECT t.*, y.name year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY y.is_current DESC,t.id DESC')->fetchAll();
    $subjects=$pdo->prepare('SELECT * FROM subjects WHERE school_id=? ORDER BY name'); $subjects->execute([$schoolId]); $subjects=$subjects->fetchAll();
    $teachers=$pdo->prepare("SELECT u.id,u.name,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.school_id=? AND r.name IN ('teacher','head_teacher','school_admin') ORDER BY u.name"); $teachers->execute([$schoolId]); $teachers=$teachers->fetchAll();
    if ($selectedClass) {
        $sql='SELECT id,student_no,first_name,last_name FROM students WHERE school_id=? AND class_id=? AND status="active"'; $params=[$schoolId,$selectedClass];
        if ($selectedStream !== '') { $sql .= ' AND stream_id=?'; $params[]=(int)$selectedStream; }
        $sql .= ' ORDER BY first_name,last_name'; $st=$pdo->prepare($sql); $st->execute($params); $students=$st->fetchAll();
        $ss=$pdo->prepare('SELECT * FROM attendance_sessions WHERE school_id=? AND class_id=? AND session_date=? AND ((stream_id IS NULL AND ? IS NULL) OR stream_id=?) LIMIT 1');
        $ss->execute([$schoolId,$selectedClass,$selectedDate,$selectedStream!==''?(int)$selectedStream:null,$selectedStream!==''?(int)$selectedStream:null]); $selectedSession=$ss->fetch();
        if ($selectedSession) {
            $es=$pdo->prepare('SELECT student_id,status,note FROM attendance_entries WHERE session_id=?'); $es->execute([$selectedSession['id']]);
            foreach($es->fetchAll() as $e){ $entries[$e['student_id']]=$e; }
        }
    }
    $slots=$pdo->prepare('SELECT ts.*, c.name class_name, st.name stream_name, sub.name subject_name, u.name teacher_name FROM timetable_slots ts JOIN classes c ON c.id=ts.class_id LEFT JOIN streams st ON st.id=ts.stream_id LEFT JOIN subjects sub ON sub.id=ts.subject_id LEFT JOIN users u ON u.id=ts.teacher_id WHERE ts.school_id=? ORDER BY ts.day_of_week, ts.start_time'); $slots->execute([$schoolId]); $slots=$slots->fetchAll();
}
$days=[1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
?>
<div class="card">
  <div class="section-title"><h2>Attendance & Timetable</h2><span class="badge warn">Phase 3</span></div>
  <p class="muted">Mark daily class attendance and manage basic timetable slots. Absentees/late students can feed into pickup and parent notifications.</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="card">
  <h3>Take Attendance</h3>
  <form method="get" class="grid grid-4" style="margin-bottom:12px;">
    <label>Class<select class="input" name="class_id" required><option value="">Select</option><?php foreach($classes as $c): ?><option value="<?= e($c['id']) ?>" <?= $selectedClass==(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Stream<select class="input" name="stream_id"><option value="">All/None</option><?php foreach($streams as $s): ?><option value="<?= e($s['id']) ?>" <?= (string)$selectedStream===(string)$s['id']?'selected':'' ?>><?= e($s['class_name'].' - '.$s['name']) ?></option><?php endforeach; ?></select></label>
    <label>Date<input class="input" type="date" name="session_date" value="<?= e($selectedDate) ?>"></label>
    <label>Term<select class="input" name="term_id"><option value="">Optional</option><?php foreach($terms as $t): ?><option value="<?= e($t['id']) ?>" <?= (string)$selectedTerm===(string)$t['id']?'selected':'' ?>><?= e($t['year_name'].' - '.$t['name']) ?></option><?php endforeach; ?></select></label>
    <div style="display:flex;align-items:end;"><button class="btn secondary" type="submit">Load Register</button></div>
  </form>
  <?php if ($selectedClass): ?>
  <form method="post"><?= CSRF::field() ?>>
    <input type="hidden" name="action" value="save_attendance"><input type="hidden" name="class_id" value="<?= e($selectedClass) ?>"><input type="hidden" name="stream_id" value="<?= e($selectedStream) ?>"><input type="hidden" name="session_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="term_id" value="<?= e($selectedTerm) ?>">
    <table class="compact"><thead><tr><th>Student</th><th>Status</th><th>Note</th></tr></thead><tbody>
      <?php foreach($students as $st): $cur=$entries[$st['id']]['status'] ?? 'present'; $note=$entries[$st['id']]['note'] ?? ''; ?>
      <tr>
        <td><?= e($st['student_no'].' - '.$st['first_name'].' '.$st['last_name']) ?></td>
        <td><select class="input" name="status[<?= e($st['id']) ?>]"><?php foreach(['present','absent','late','excused'] as $opt): ?><option value="<?= $opt ?>" <?= $cur===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></td>
        <td><input class="input" name="note[<?= e($st['id']) ?>]" value="<?= e($note) ?>" placeholder="optional note"></td>
      </tr>
      <?php endforeach; if(!$students): ?><tr><td colspan="3" class="muted">No active students found for this class/stream.</td></tr><?php endif; ?>
    </tbody></table>
    <?php if($students && $canManage): ?><div style="margin-top:12px;"><button class="btn" type="submit">Save Attendance</button></div><?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <div class="section-title"><h3>Timetable Slots</h3></div>
  <?php if (in_array($user['role_name'], ['super_admin','school_admin','head_teacher'], true)): ?>
  <form method="post"><?= CSRF::field() ?> class="grid grid-4" style="margin-bottom:12px;">
    <input type="hidden" name="action" value="add_timetable">
    <label>Class<select class="input" name="class_id" required><?php foreach($classes as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Stream<select class="input" name="stream_id"><option value="">All</option><?php foreach($streams as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['class_name'].' - '.$s['name']) ?></option><?php endforeach; ?></select></label>
    <label>Subject<select class="input" name="subject_id"><option value="">Select</option><?php foreach($subjects as $sub): ?><option value="<?= e($sub['id']) ?>"><?= e($sub['name']) ?></option><?php endforeach; ?></select></label>
    <label>Teacher<select class="input" name="teacher_id"><option value="">Select</option><?php foreach($teachers as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
    <label>Day<select class="input" name="day_of_week"><?php foreach($days as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
    <label>Start<input class="input" type="time" name="start_time" required></label>
    <label>End<input class="input" type="time" name="end_time" required></label>
    <label>Room<input class="input" name="room"></label>
    <div style="display:flex;align-items:end;"><button class="btn" type="submit">Add Slot</button></div>
  </form>
  <?php endif; ?>
  <table class="compact"><thead><tr><th>Day</th><th>Time</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Room</th><th></th></tr></thead><tbody>
    <?php foreach($slots as $sl): ?><tr>
      <td><?= e($days[$sl['day_of_week']] ?? $sl['day_of_week']) ?></td><td><?= e(substr($sl['start_time'],0,5).'-'.substr($sl['end_time'],0,5)) ?></td>
      <td><?= e($sl['class_name'].($sl['stream_name']?' / '.$sl['stream_name']:'')) ?></td><td><?= e($sl['subject_name']?:'-') ?></td><td><?= e($sl['teacher_name']?:'-') ?></td><td><?= e($sl['room']?:'-') ?></td>
      <td><?php if(in_array($user['role_name'], ['super_admin','school_admin','head_teacher'], true)): ?><form method="post"><?= CSRF::field() ?> onsubmit="return confirm('Delete slot?')"><input type="hidden" name="action" value="delete_timetable"><input type="hidden" name="id" value="<?= e($sl['id']) ?>"><button class="btn secondary" type="submit">Delete</button></form><?php endif; ?></td>
    </tr><?php endforeach; if(!$slots): ?><tr><td colspan="7" class="muted">No timetable slots yet.</td></tr><?php endif; ?>
  </tbody></table>
</div>






