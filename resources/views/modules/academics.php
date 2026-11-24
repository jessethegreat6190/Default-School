<?php
RBAC::require('academics.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher','teacher'], true);

function grade_for_score(PDO $pdo, int $schoolId, float $score): array {
    $st = $pdo->prepare('SELECT gr.* FROM grade_rules gr JOIN grading_scales gs ON gs.id=gr.grading_scale_id WHERE gs.school_id=? ORDER BY gs.is_default DESC, gr.sort_order ASC');
    $st->execute([$schoolId]);
    foreach ($st->fetchAll() as $r) {
        if ($score >= (float)$r['min_score'] && $score <= (float)$r['max_score']) return $r;
    }
    return ['grade'=>'-', 'remark'=>'', 'division'=>''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_assessment') {
            $stmt=$pdo->prepare('INSERT INTO assessments (school_id,term_id,class_id,name,max_score,weight,assessment_date,created_by) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$schoolId,$_POST['term_id']?:null,$_POST['class_id']?:null,trim($_POST['name']),(float)$_POST['max_score'],(float)$_POST['weight'],$_POST['assessment_date']?:null,$user['id']]);
            Audit::log('assessment.create','assessment',$pdo->lastInsertId(),null,$_POST); $message='Assessment created.';
        }
        if ($action === 'save_marks') {
            $assessmentId=(int)$_POST['assessment_id']; $subjectId=(int)$_POST['subject_id'];
            $scores = $_POST['score'] ?? [];
            $stmt=$pdo->prepare('INSERT INTO marks (school_id,assessment_id,student_id,subject_id,score,grade,remark,recorded_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), grade=VALUES(grade), remark=VALUES(remark), recorded_by=VALUES(recorded_by), updated_at=NOW()');
            foreach ($scores as $studentId => $scoreRaw) {
                if ($scoreRaw === '' || $scoreRaw === null) continue;
                $score = (float)$scoreRaw;
                $g = grade_for_score($pdo,$schoolId,$score);
                $stmt->execute([$schoolId,$assessmentId,(int)$studentId,$subjectId,$score,$g['grade'],$g['remark'],$user['id']]);
            }
            Audit::log('marks.save','assessment',$assessmentId,null,['subject_id'=>$subjectId,'count'=>count($scores)]);
            $message='Marks saved.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$classes=$terms=$subjects=$assessments=$students=$existingMarks=[]; $selectedAssessment=(int)($_GET['assessment_id']??0); $selectedSubject=(int)($_GET['subject_id']??0); $selectedClass=(int)($_GET['class_id']??0);
if ($pdo) {
    $classes=$pdo->prepare('SELECT * FROM classes WHERE school_id=? ORDER BY name'); $classes->execute([$schoolId]); $classes=$classes->fetchAll();
    $terms=$pdo->query('SELECT t.*, y.name year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY t.id DESC')->fetchAll();
    $subjects=$pdo->prepare('SELECT * FROM subjects WHERE school_id=? ORDER BY name'); $subjects->execute([$schoolId]); $subjects=$subjects->fetchAll();
    $assessments=$pdo->prepare('SELECT a.*, c.name class_name, t.name term_name FROM assessments a LEFT JOIN classes c ON c.id=a.class_id LEFT JOIN terms t ON t.id=a.term_id WHERE a.school_id=? ORDER BY a.id DESC LIMIT 50'); $assessments->execute([$schoolId]); $assessments=$assessments->fetchAll();
    $classForStudents = $selectedClass;
    if ($selectedAssessment) {
        foreach($assessments as $a){ if((int)$a['id']===$selectedAssessment){ $classForStudents = (int)$a['class_id']; break; } }
    }
    if ($classForStudents) {
        $st=$pdo->prepare('SELECT id,student_no,first_name,last_name FROM students WHERE school_id=? AND class_id=? AND status="active" ORDER BY first_name,last_name');
        $st->execute([$schoolId,$classForStudents]); $students=$st->fetchAll();
    }
    if ($selectedAssessment && $selectedSubject) {
        $st=$pdo->prepare('SELECT student_id,score,grade,remark FROM marks WHERE assessment_id=? AND subject_id=?');
        $st->execute([$selectedAssessment,$selectedSubject]);
        foreach($st->fetchAll() as $m){ $existingMarks[$m['student_id']]=$m; }
    }
}
$reportRows=[]; $reportAssessment=(int)($_GET['report_assessment_id']??0); $reportClass=(int)($_GET['report_class_id']??0);
if ($pdo && $reportAssessment && $reportClass) {
    $st=$pdo->prepare('SELECT s.id,s.student_no,s.first_name,s.last_name, sub.name subject_name, m.score, m.grade FROM students s JOIN marks m ON m.student_id=s.id JOIN subjects sub ON sub.id=m.subject_id WHERE s.school_id=? AND s.class_id=? AND m.assessment_id=? ORDER BY s.first_name,s.last_name, sub.name');
    $st->execute([$schoolId,$reportClass,$reportAssessment]);
    foreach($st->fetchAll() as $r){ $reportRows[$r['id']]['student']=$r; $reportRows[$r['id']]['subjects'][]=$r; $reportRows[$r['id']]['total']=($reportRows[$r['id']]['total']??0)+(float)$r['score']; $reportRows[$r['id']]['count']=($reportRows[$r['id']]['count']??0)+1; }
}
?>
<div class="card">
  <div class="section-title"><h2>Academics & Report Cards</h2><span class="badge warn">Phase 6</span></div>
  <p class="muted">Assessments, marks entry, marklists, aggregates/divisions, and printable report card view.</p>
  <?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Create Assessment</h3>
    <?php if($canManage): ?><form method="post"><?= CSRF::field() ?> class="grid">
      <input type="hidden" name="action" value="create_assessment">
      <label>Name<input class="input" name="name" placeholder="Mid Term / End of Term" required></label>
      <div class="grid grid-2"><label>Class<select class="input" name="class_id"><option value="">All/Select</option><?php foreach($classes as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <label>Term<select class="input" name="term_id"><option value="">Optional</option><?php foreach($terms as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['year_name'].' - '.$t['name']) ?></option><?php endforeach; ?></select></label></div>
      <div class="grid grid-3"><label>Max score<input class="input" type="number" step="0.01" name="max_score" value="100"></label><label>Weight<input class="input" type="number" step="0.01" name="weight" value="100"></label><label>Date<input class="input" type="date" name="assessment_date"></label></div>
      <button class="btn" type="submit">Create Assessment</button>
    </form><?php endif; ?>
  </div>
  <div class="card">
    <h3>Marks Entry</h3>
    <form method="get" class="grid" style="margin-bottom:12px;">
      <label>Assessment<select class="input" name="assessment_id" required><option value="">Select</option><?php foreach($assessments as $a): ?><option value="<?= e($a['id']) ?>" <?= $selectedAssessment===(int)$a['id']?'selected':'' ?>><?= e($a['name'].' - '.($a['class_name']?:'No class')) ?></option><?php endforeach; ?></select></label>
      <label>Subject<select class="input" name="subject_id" required><option value="">Select</option><?php foreach($subjects as $s): ?><option value="<?= e($s['id']) ?>" <?= $selectedSubject===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
      <button class="btn secondary" type="submit">Load Students</button>
    </form>
    <?php if($selectedAssessment && $selectedSubject): ?>
    <form method="post"><?= CSRF::field() ?>>
      <input type="hidden" name="action" value="save_marks"><input type="hidden" name="assessment_id" value="<?= e($selectedAssessment) ?>"><input type="hidden" name="subject_id" value="<?= e($selectedSubject) ?>">
      <table class="compact"><thead><tr><th>Student</th><th>Score</th><th>Existing Grade</th></tr></thead><tbody>
      <?php foreach($students as $s): $em=$existingMarks[$s['id']]??null; ?><tr><td><?= e($s['student_no'].' '.$s['first_name'].' '.$s['last_name']) ?></td><td><input class="input" type="number" step="0.01" name="score[<?= e($s['id']) ?>]" value="<?= e($em['score'] ?? '') ?>"></td><td><?= e($em['grade'] ?? '-') ?></td></tr><?php endforeach; if(!$students): ?><tr><td colspan="3" class="muted">Select an assessment linked to a class with students.</td></tr><?php endif; ?>
      </tbody></table>
      <?php if($students): ?><div style="margin-top:12px;"><button class="btn" type="submit">Save Marks</button></div><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
</div>
<div class="card">
  <div class="section-title"><h3>Marklist / Report Preview</h3></div>
  <form method="get" class="grid grid-4" style="margin-bottom:12px;">
    <input type="hidden" name="assessment_id" value="<?= e($selectedAssessment) ?>"><input type="hidden" name="subject_id" value="<?= e($selectedSubject) ?>">
    <label>Assessment<select class="input" name="report_assessment_id"><option value="">Select</option><?php foreach($assessments as $a): ?><option value="<?= e($a['id']) ?>" <?= $reportAssessment===(int)$a['id']?'selected':'' ?>><?= e($a['name'].' - '.($a['class_name']?:'')) ?></option><?php endforeach; ?></select></label>
    <label>Class<select class="input" name="report_class_id"><option value="">Select</option><?php foreach($classes as $c): ?><option value="<?= e($c['id']) ?>" <?= $reportClass===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <div style="display:flex;align-items:end;"><button class="btn secondary" type="submit">Generate Preview</button></div>
  </form>
  <table class="compact"><thead><tr><th>Student</th><th>Subjects</th><th>Total</th><th>Average</th><th>Division</th></tr></thead><tbody>
    <?php foreach($reportRows as $row): $avg=$row['count']?($row['total']/$row['count']):0; $g=grade_for_score($pdo,$schoolId,$avg); ?>
    <tr><td><?= e($row['student']['student_no'].' '.$row['student']['first_name'].' '.$row['student']['last_name']) ?></td><td><?= e(count($row['subjects'])) ?></td><td><?= e(number_format($row['total'],2)) ?></td><td><?= e(number_format($avg,2)) ?></td><td><span class="badge ok"><?= e($g['division'] ?: $g['grade']) ?></span> <a class="btn secondary" style="padding:4px 8px;font-size:.75rem;" href="<?= e(asset_path('/report-card/print?student_id='.$row['student']['id'].'&assessment_id='.$reportAssessment)) ?>" target="_blank">Print</a></td></tr>
    <?php endforeach; if(!$reportRows): ?><tr><td colspan="5" class="muted">Select assessment and class to preview aggregates/divisions.</td></tr><?php endif; ?>
  </tbody></table>
</div>






