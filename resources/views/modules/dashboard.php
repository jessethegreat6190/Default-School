<?php
$pdo = DB::pdo(); $schoolId = (int)(Auth::user()['school_id'] ?? 1);
$counts = ['students'=>0,'attendance'=>0,'pickups'=>0,'fees'=>0];
$recentPickups = []; $absentToday = [];
if ($pdo) {
    try { $st=$pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id=? AND status="active"'); $st->execute([$schoolId]); $counts['students']=(int)$st->fetchColumn(); } catch(Throwable $e){}
    try { $st=$pdo->prepare('SELECT COUNT(*) FROM attendance_entries ae JOIN attendance_sessions s ON s.id=ae.session_id WHERE s.school_id=? AND s.session_date=CURDATE() AND ae.status="present"'); $st->execute([$schoolId]); $present=(int)$st->fetchColumn();
          $st=$pdo->prepare('SELECT COUNT(*) FROM attendance_entries ae JOIN attendance_sessions s ON s.id=ae.session_id WHERE s.school_id=? AND s.session_date=CURDATE()'); $st->execute([$schoolId]); $total=(int)$st->fetchColumn();
          $counts['attendance']=$total?round($present/$total*100):0; } catch(Throwable $e){}
    try { $st=$pdo->prepare('SELECT COUNT(*) FROM pickup_logs WHERE school_id=? AND DATE(checked_out_at)=CURDATE()'); $st->execute([$schoolId]); $counts['pickups']=(int)$st->fetchColumn(); } catch(Throwable $e){}
    try { $st=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE school_id=? AND status='posted' AND DATE(paid_at)=CURDATE()'); $st->execute([$schoolId]); $counts['fees']=(float)$st->fetchColumn(); } catch(Throwable $e){}
    try { $st=$pdo->prepare('SELECT p.*, s.first_name,s.last_name,s.student_no FROM pickup_logs p JOIN students s ON s.id=p.student_id WHERE p.school_id=? ORDER BY p.checked_out_at DESC LIMIT 5'); $st->execute([$schoolId]); $recentPickups=$st->fetchAll(); } catch(Throwable $e){}
    try { $st=$pdo->prepare('SELECT s.student_no,s.first_name,s.last_name,c.name class_name FROM attendance_entries ae JOIN attendance_sessions sess ON sess.id=ae.session_id JOIN students s ON s.id=ae.student_id LEFT JOIN classes c ON c.id=s.class_id WHERE sess.school_id=? AND sess.session_date=CURDATE() AND ae.status IN ("absent","late") ORDER BY ae.status,s.first_name LIMIT 8'); $st->execute([$schoolId]); $absentToday=$st->fetchAll(); } catch(Throwable $e){}
}
$cards = [
    ['label' => 'Active students', 'value' => (string)$counts['students'], 'meta' => 'Enrolled in registry', 'badge' => 'Live'],
    ['label' => 'Attendance today', 'value' => $counts['attendance'].'%', 'meta' => 'Present / marked today', 'badge' => 'Live'],
    ['label' => 'Pickups verified', 'value' => (string)$counts['pickups'], 'meta' => 'Checked out today', 'badge' => 'Live'],
    ['label' => 'Fees collected today', 'value' => 'UGX '.number_format($counts['fees'],0), 'meta' => 'Payments recorded today', 'badge' => 'Live'],
];
?>
<div class="grid grid-4">
  <?php foreach ($cards as $card): ?>
    <div class="card stat">
      <div class="label"><?= e($card['label']) ?></div>
      <div class="value"><?= e($card['value']) ?></div>
      <div class="meta"><?= e($card['meta']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <div class="section-title"><h2>Recent Pickups</h2><span class="badge ok">Safety log</span></div>
    <table class="compact"><thead><tr><th>Student</th><th>Picked by</th><th>Method</th><th>Time</th></tr></thead><tbody>
      <?php foreach($recentPickups as $p): ?><tr><td><?= e($p['student_no'].' '.$p['first_name'].' '.$p['last_name']) ?></td><td><?= e($p['collector_name']) ?></td><td><?= e($p['verification_method']) ?></td><td><?= e($p['checked_out_at']) ?></td></tr><?php endforeach; if(!$recentPickups): ?><tr><td colspan="4" class="muted">No pickup logs yet. Safe Pickup module is next.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <div class="card">
    <div class="section-title"><h2>Absent / Late Today</h2><span class="badge warn">Attention</span></div>
    <table class="compact"><thead><tr><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody>
      <?php foreach($absentToday as $a): ?><tr><td><?= e($a['student_no'].' '.$a['first_name'].' '.$a['last_name']) ?></td><td><?= e($a['class_name']?:'-') ?></td><td><span class="badge warn">Flag</span></td></tr><?php endforeach; if(!$absentToday): ?><tr><td colspan="3" class="muted">No absent/late records marked today.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

