<?php
Auth::requireLogin();
RBAC::require('academics.view');
$pdo = DB::pdo(); $schoolId = (int)(Auth::user()['school_id'] ?? 1);
$studentId = (int)($_GET['student_id'] ?? 0);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$school = config('school');
$student=null; $rows=[]; $assessment=null; $total=0; $count=0;
if ($pdo && $studentId && $assessmentId) {
    $st=$pdo->prepare('SELECT s.*, c.name class_name, st.name stream_name FROM students s LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN streams st ON st.id=s.stream_id WHERE s.id=? AND s.school_id=?'); $st->execute([$studentId,$schoolId]); $student=$st->fetch();
    $st=$pdo->prepare('SELECT a.*, t.name term_name, y.name year_name FROM assessments a LEFT JOIN terms t ON t.id=a.term_id LEFT JOIN academic_years y ON y.id=t.academic_year_id WHERE a.id=? AND a.school_id=?'); $st->execute([$assessmentId,$schoolId]); $assessment=$st->fetch();
    $st=$pdo->prepare('SELECT sub.name subject_name, m.score, m.grade, m.remark FROM marks m JOIN subjects sub ON sub.id=m.subject_id WHERE m.student_id=? AND m.assessment_id=? ORDER BY sub.name'); $st->execute([$studentId,$assessmentId]); $rows=$st->fetchAll();
    foreach($rows as $r){ if($r['score']!==null){ $total+=(float)$r['score']; $count++; } }
}
$avg=$count?$total/$count:0;
function grade_lookup($pdo,$schoolId,$score){ $st=$pdo->prepare('SELECT gr.* FROM grade_rules gr JOIN grading_scales gs ON gs.id=gr.grading_scale_id WHERE gs.school_id=? ORDER BY gs.is_default DESC, gr.sort_order ASC'); $st->execute([$schoolId]); foreach($st->fetchAll() as $r){ if($score>=(float)$r['min_score'] && $score<=(float)$r['max_score']) return $r; } return ['grade'=>'-','division'=>'-','remark'=>'-']; }
$overall = grade_lookup($pdo,$schoolId,$avg);
?><!doctype html><html><head><meta charset="utf-8"><title>Report Card</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;color:#182230;} .hdr{display:flex;gap:16px;align-items:center;border-bottom:2px solid #ddd;padding-bottom:12px;margin-bottom:16px;} .hdr img{width:64px;height:64px;object-fit:contain;} table{width:100%;border-collapse:collapse;margin-top:12px;} th,td{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f4f7fb;} .meta{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:12px 0;} .box{padding:8px;border:1px solid #ddd;border-radius:8px;} .print{margin:16px 0;} @media print{.print{display:none}}
</style></head><body>
<div class="print"><button onclick="window.print()">Print</button></div>
<?php if(!$student || !$assessment): ?><p>Missing student/assessment or not found.</p><?php else: ?>
<div class="hdr"><img src="<?= e($school['logo']??'') ?>" alt="logo"><div><h2 style="margin:0;"><?= e($school['name']??'School') ?></h2><div><?= e($school['motto']??config('app.tagline')) ?></div><div><strong>Student Report Card</strong></div></div></div>
<div class="meta">
  <div class="box"><strong>Student:</strong> <?= e($student['student_no'].' '.$student['first_name'].' '.$student['last_name']) ?></div>
  <div class="box"><strong>Class:</strong> <?= e(($student['class_name']?:'-').($student['stream_name']?' / '.$student['stream_name']:'')) ?></div>
  <div class="box"><strong>Assessment:</strong> <?= e($assessment['name']) ?></div>
  <div class="box"><strong>Term/Year:</strong> <?= e(($assessment['term_name']?:'-').'/'.($assessment['year_name']?:'-')) ?></div>
</div>
<table><thead><tr><th>Subject</th><th>Score</th><th>Grade</th><th>Remark</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= e($r['subject_name']) ?></td><td><?= e($r['score']) ?></td><td><?= e($r['grade']) ?></td><td><?= e($r['remark']) ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="4">No marks recorded.</td></tr><?php endif; ?>
</tbody></table>
<table><tbody>
<tr><th>Total</th><td><?= e(number_format($total,2)) ?></td><th>Average</th><td><?= e(number_format($avg,2)) ?></td></tr>
<tr><th>Grade</th><td><?= e($overall['grade']) ?></td><th>Division</th><td><?= e($overall['division']) ?></td></tr>
<tr><th>Overall Remark</th><td colspan="3"><?= e($overall['remark']) ?></td></tr>
</tbody></table>
<?php endif; ?></body></html>
