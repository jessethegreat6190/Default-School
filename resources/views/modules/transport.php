<?php
RBAC::require('dashboard.view');
$pdo = DB::pdo(); $error = DB::error(); $message = null;
$schoolId = (int)(Auth::user()['school_id'] ?? 1);
$user = Auth::user();
$canManage = in_array($user['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $canManage) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_route') {
            $pdo->prepare('INSERT INTO transport_routes (school_id,name,code,description) VALUES (?,?,?,?)')->execute([$schoolId,trim($_POST['name']),trim($_POST['code']?:null),trim($_POST['description']?:null)]);
            Audit::log('transport.route.create','transport_route',$pdo->lastInsertId(),null,$_POST); $message='Route added.';
        }
        if ($action === 'add_vehicle') {
            $pdo->prepare('INSERT INTO transport_vehicles (school_id,route_id,plate_number,driver_name,driver_phone,capacity) VALUES (?,?,?,?,?,?)')->execute([$schoolId,$_POST['route_id']?:null,trim($_POST['plate_number']),trim($_POST['driver_name']?:null),trim($_POST['driver_phone']?:null),$_POST['capacity']!==''?(int)$_POST['capacity']:null]);
            Audit::log('transport.vehicle.create','transport_vehicle',$pdo->lastInsertId(),null,$_POST); $message='Vehicle added.';
        }
        if ($action === 'add_stop') {
            $pdo->prepare('INSERT INTO transport_stops (route_id,name,pickup_time,dropoff_time,sort_order) VALUES (?,?,?,?,?)')->execute([(int)$_POST['route_id'],trim($_POST['name']),$_POST['pickup_time']?:null,$_POST['dropoff_time']?:null,(int)($_POST['sort_order']??0)]);
            Audit::log('transport.stop.create','transport_stop',$pdo->lastInsertId(),null,$_POST); $message='Stop added.';
        }
        if ($action === 'assign_student') {
            $pdo->prepare('INSERT INTO student_transport (school_id,student_id,route_id,stop_id,pickup_point,is_active) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE route_id=VALUES(route_id), stop_id=VALUES(stop_id), pickup_point=VALUES(pickup_point), is_active=1')->execute([$schoolId,(int)$_POST['student_id'],$_POST['route_id']?:null,$_POST['stop_id']?:null,trim($_POST['pickup_point']?:null)]);
            Audit::log('transport.assign','student_transport',$_POST['student_id'],null,$_POST); $message='Student transport assigned.';
        }
    } catch(Throwable $e){ $error=$e->getMessage(); }
}

$routes=$vehicles=$stops=$students=$assignments=[];
if($pdo && DB::hasTable('transport_routes')){
  $st=$pdo->prepare('SELECT * FROM transport_routes WHERE school_id=? ORDER BY name'); $st->execute([$schoolId]); $routes=$st->fetchAll();
  $st=$pdo->prepare('SELECT v.*, r.name route_name FROM transport_vehicles v LEFT JOIN transport_routes r ON r.id=v.route_id WHERE v.school_id=? ORDER BY v.id DESC'); $st->execute([$schoolId]); $vehicles=$st->fetchAll();
  $stops=$pdo->query('SELECT s.*, r.name route_name FROM transport_stops s JOIN transport_routes r ON r.id=s.route_id ORDER BY r.name, s.sort_order, s.pickup_time')->fetchAll();
  $st=$pdo->prepare('SELECT id,student_no,first_name,last_name FROM students WHERE school_id=? AND status="active" ORDER BY first_name,last_name'); $st->execute([$schoolId]); $students=$st->fetchAll();
  $st=$pdo->prepare('SELECT st.*, s.student_no,s.first_name,s.last_name,r.name route_name,sp.name stop_name FROM student_transport st JOIN students s ON s.id=st.student_id LEFT JOIN transport_routes r ON r.id=st.route_id LEFT JOIN transport_stops sp ON sp.id=st.stop_id WHERE st.school_id=? ORDER BY s.first_name'); $st->execute([$schoolId]); $assignments=$st->fetchAll();
}
?>
<div class="card"><div class="section-title"><h2>Transport / Bus</h2><span class="badge warn">Phase 8</span></div><p class="muted">Routes, vehicles, stops, and student transport assignments.</p>
<?php if($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?></div>
<div class="grid grid-2">
  <div class="card"><h3>Routes</h3>
    <?php if($canManage): ?><form method="post" class="grid" style="margin-bottom:12px;"><?= CSRF::field() ?><input type="hidden" name="action" value="add_route"><label>Name<input class="input" name="name" required></label><label>Code<input class="input" name="code"></label><label>Description<textarea class="input" name="description" rows="2"></textarea></label><button class="btn" type="submit">Add Route</button></form><?php endif; ?>
    <table class="compact"><thead><tr><th>Name</th><th>Code</th><th>Active</th></tr></thead><tbody><?php foreach($routes as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['code']?:'-') ?></td><td><?= $r['is_active']?'<span class="badge ok">Yes</span>':'-' ?></td></tr><?php endforeach; if(!$routes): ?><tr><td colspan="3" class="muted">No routes.</td></tr><?php endif; ?></tbody></table>
  </div>
  <div class="card"><h3>Vehicles</h3>
    <?php if($canManage): ?><form method="post" class="grid" style="margin-bottom:12px;"><?= CSRF::field() ?><input type="hidden" name="action" value="add_vehicle"><label>Route<select class="input" name="route_id"><option value="">None</option><?php foreach($routes as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label><label>Plate<input class="input" name="plate_number" required></label><div class="grid grid-2"><label>Driver<input class="input" name="driver_name"></label><label>Phone<input class="input" name="driver_phone"></label></div><label>Capacity<input class="input" type="number" name="capacity"></label><button class="btn" type="submit">Add Vehicle</button></form><?php endif; ?>
    <table class="compact"><thead><tr><th>Plate</th><th>Route</th><th>Driver</th></tr></thead><tbody><?php foreach($vehicles as $v): ?><tr><td><?= e($v['plate_number']) ?></td><td><?= e($v['route_name']?:'-') ?></td><td><?= e($v['driver_name']?:'-') ?></td></tr><?php endforeach; if(!$vehicles): ?><tr><td colspan="3" class="muted">No vehicles.</td></tr><?php endif; ?></tbody></table>
  </div>
</div>
<div class="grid grid-2">
  <div class="card"><h3>Stops</h3>
    <?php if($canManage): ?><form method="post" class="grid" style="margin-bottom:12px;"><?= CSRF::field() ?><input type="hidden" name="action" value="add_stop"><label>Route<select class="input" name="route_id" required><?php foreach($routes as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label><label>Stop name<input class="input" name="name" required></label><div class="grid grid-3"><label>Pickup<input class="input" type="time" name="pickup_time"></label><label>Dropoff<input class="input" type="time" name="dropoff_time"></label><label>Order<input class="input" type="number" name="sort_order" value="0"></label></div><button class="btn" type="submit">Add Stop</button></form><?php endif; ?>
    <table class="compact"><thead><tr><th>Route</th><th>Stop</th><th>Pickup</th></tr></thead><tbody><?php foreach($stops as $s): ?><tr><td><?= e($s['route_name']) ?></td><td><?= e($s['name']) ?></td><td><?= e($s['pickup_time']?substr($s['pickup_time'],0,5):'-') ?></td></tr><?php endforeach; if(!$stops): ?><tr><td colspan="3" class="muted">No stops.</td></tr><?php endif; ?></tbody></table>
  </div>
  <div class="card"><h3>Student Assignment</h3>
    <?php if($canManage): ?><form method="post" class="grid" style="margin-bottom:12px;"><?= CSRF::field() ?><input type="hidden" name="action" value="assign_student"><label>Student<select class="input" name="student_id" required><?php foreach($students as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['student_no'].' '.$s['first_name'].' '.$s['last_name']) ?></option><?php endforeach; ?></select></label><label>Route<select class="input" name="route_id"><option value="">None</option><?php foreach($routes as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label><label>Stop<select class="input" name="stop_id"><option value="">None</option><?php foreach($stops as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['route_name'].' - '.$s['name']) ?></option><?php endforeach; ?></select></label><label>Pickup point<input class="input" name="pickup_point"></label><button class="btn" type="submit">Assign</button></form><?php endif; ?>
    <table class="compact"><thead><tr><th>Student</th><th>Route</th><th>Stop</th></tr></thead><tbody><?php foreach($assignments as $a): ?><tr><td><?= e($a['student_no'].' '.$a['first_name']) ?></td><td><?= e($a['route_name']?:'-') ?></td><td><?= e($a['stop_name']?:$a['pickup_point']?:'-') ?></td></tr><?php endforeach; if(!$assignments): ?><tr><td colspan="3" class="muted">No assignments.</td></tr><?php endif; ?></tbody></table>
  </div>
</div>
