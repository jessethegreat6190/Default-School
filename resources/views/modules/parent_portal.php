<?php
Auth::requireLogin();
$user = Auth::user();
if (($user['role_name'] ?? '') !== 'parent') { RBAC::require('dashboard.view'); }
$pdo = DB::pdo(); $schoolId = (int)($user['school_id'] ?? 1);
$children = []; $feeRows=[]; $attendance=[]; $marks=[]; $pickups=[]; $ann=[];
if ($pdo) {
    $studentIds = [];
    if (DB::hasTable('parent_users')) {
        $st=$pdo->prepare('SELECT guardian_id FROM parent_users WHERE user_id=? LIMIT 1'); $st->execute([$user['id']]); $gid=$st->fetchColumn();
        if ($gid) {
            $st=$pdo->prepare('SELECT s.*, c.name class_name, st.name stream_name FROM students s JOIN student_guardians sg ON sg.student_id=s.id LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN streams st ON st.id=s.stream_id WHERE sg.guardian_id=? AND s.school_id=?');
            $st->execute([$gid,$schoolId]); $children=$st->fetchAll();
        }
    }
    if (!$children && !empty($user['email'])) {
        $st=$pdo->prepare('SELECT s.*, c.name class_name, st.name stream_name FROM students s JOIN student_guardians sg ON sg.student_id=s.id JOIN guardians g ON g.id=sg.guardian_id LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN streams st ON st.id=s.stream_id WHERE g.email=? AND s.school_id=?');
        $st->execute([$user['email'],$schoolId]); $children=$st->fetchAll();
    }
    foreach($children as $ch){ $studentIds[]=(int)$ch['id']; }
    if ($studentIds) {
        $in = implode(',', array_fill(0,count($studentIds),'?'));
        $st=$pdo->prepare("SELECT fa.*, s.first_name,s.last_name,s.student_no FROM fee_accounts fa JOIN students s ON s.id=fa.student_id WHERE fa.student_id IN ($in)"); $st->execute($studentIds); $feeRows=$st->fetchAll();
        $st=$pdo->prepare("SELECT ae.status,ae.note,sess.session_date,s.student_no,s.first_name FROM attendance_entries ae JOIN attendance_sessions sess ON sess.id=ae.session_id JOIN students s ON s.id=ae.student_id WHERE ae.student_id IN ($in) ORDER BY sess.session_date DESC LIMIT 20"); $st->execute($studentIds); $attendance=$st->fetchAll();
        $st=$pdo->prepare("SELECT m.score,m.grade,sub.name subject_name,a.name assessment_name,s.student_no,s.first_name FROM marks m JOIN subjects sub ON sub.id=m.subject_id JOIN assessments a ON a.id=m.assessment_id JOIN students s ON s.id=m.student_id WHERE m.student_id IN ($in) ORDER BY m.id DESC LIMIT 30"); $st->execute($studentIds); $marks=$st->fetchAll();
        $st=$pdo->prepare("SELECT p.*, s.student_no,s.first_name FROM pickup_logs p JOIN students s ON s.id=p.student_id WHERE p.student_id IN ($in) ORDER BY p.checked_out_at DESC LIMIT 20"); $st->execute($studentIds); $pickups=$st->fetchAll();
    }
    if (DB::hasTable('announcements')) {
        $st=$pdo->prepare("SELECT * FROM announcements WHERE school_id=? AND status='published' AND audience IN ('all','parents') ORDER BY published_at DESC LIMIT 10"); $st->execute([$schoolId]); $ann=$st->fetchAll();
    }
}
?>
<div class="card">
  <div class="section-title"><h2>Parent Portal</h2><span class="badge ok">Live</span></div>
  <p class="muted">View your child’s attendance, fees, marks, pickup history, and school announcements.</p>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>My Children</h3>
    <table class="compact"><thead><tr><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody>
      <?php foreach($children as $c): ?><tr><td><?= e($c['student_no'].' '.$c['first_name'].' '.$c['last_name']) ?></td><td><?= e(($c['class_name']?:'-').($c['stream_name']?' / '.$c['stream_name']:'')) ?></td><td><span class="badge ok"><?= e($c['status']) ?></span></td></tr><?php endforeach; if(!$children): ?><tr><td colspan="3" class="muted">No linked children found. Ask admin to link your parent account/guardian email.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <div class="card">
    <h3>Fee Balances</h3>
    <table class="compact"><thead><tr><th>Student</th><th>Balance</th><th>Status</th></tr></thead><tbody>
      <?php foreach($feeRows as $f): ?><tr><td><?= e($f['student_no'].' '.$f['first_name']) ?></td><td><?= e($f['currency'].' '.number_format((float)$f['balance'],2)) ?></td><td><span class="badge <?= ((float)$f['balance']>0)?'warn':'ok' ?>"><?= ((float)$f['balance']>0)?'Due':'Clear' ?></span></td></tr><?php endforeach; if(!$feeRows): ?><tr><td colspan="3" class="muted">No fee accounts.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<div class="grid grid-2">
  <div class="card"><h3>Recent Attendance</h3><table class="compact"><thead><tr><th>Date</th><th>Student</th><th>Status</th></tr></thead><tbody>
    <?php foreach($attendance as $a): ?><tr><td><?= e($a['session_date']) ?></td><td><?= e($a['student_no']) ?></td><td><span class="badge <?= $a['status']==='present'?'ok':'warn' ?>"><?= e($a['status']) ?></span></td></tr><?php endforeach; if(!$attendance): ?><tr><td colspan="3" class="muted">No attendance records.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card"><h3>Recent Pickup Logs</h3><table class="compact"><thead><tr><th>Time</th><th>Student</th><th>Collector</th><th>Status</th></tr></thead><tbody>
    <?php foreach($pickups as $p): ?><tr><td><?= e($p['checked_out_at']) ?></td><td><?= e($p['student_no']) ?></td><td><?= e($p['collector_name']) ?></td><td><span class="badge ok"><?= e($p['status']) ?></span></td></tr><?php endforeach; if(!$pickups): ?><tr><td colspan="4" class="muted">No pickup logs.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="grid grid-2">
  <div class="card"><h3>Recent Marks</h3><table class="compact"><thead><tr><th>Student</th><th>Assessment</th><th>Subject</th><th>Score</th><th>Grade</th></tr></thead><tbody>
    <?php foreach($marks as $m): ?><tr><td><?= e($m['student_no']) ?></td><td><?= e($m['assessment_name']) ?></td><td><?= e($m['subject_name']) ?></td><td><?= e($m['score']) ?></td><td><?= e($m['grade']) ?></td></tr><?php endforeach; if(!$marks): ?><tr><td colspan="5" class="muted">No marks yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card"><h3>Announcements</h3><?php foreach($ann as $a): ?><div class="empty" style="margin-bottom:10px;"><strong><?= e($a['title']) ?></strong><div class="muted" style="font-size:.78rem;"><?= e($a['published_at']) ?></div><div style="margin-top:6px;"><?= nl2br(e($a['body'])) ?></div></div><?php endforeach; if(!$ann): ?><div class="muted">No announcements.</div><?php endif; ?></div>
</div>
