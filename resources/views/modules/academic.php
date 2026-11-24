<?php
RBAC::require('dashboard.view'); // admins/head/teachers etc; all internal roles have dashboard.view
$pdo = DB::pdo();
$message = null; $error = DB::error();
$schoolId = (int) (Auth::user()['school_id'] ?? 1);

function post($k,$d=''){ return trim((string)($_POST[$k] ?? $d)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    RBAC::require('dashboard.view');
    $action = $_POST['action'] ?? '';
    try {
        if (in_array($action, ['add_year','add_term','add_class','add_stream','add_subject','set_current_year','set_current_term','delete_year','delete_term','delete_class','delete_stream','delete_subject'], true)) {
            RBAC::require('dashboard.view'); // refine below
        }
        $adminOnly = ['add_year','add_term','add_class','add_stream','add_subject','set_current_year','set_current_term','delete_year','delete_term','delete_class','delete_stream','delete_subject'];
        if (in_array($action, $adminOnly, true) && !in_array(Auth::user()['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true)) {
            throw new RuntimeException('Only admins can modify academic setup.');
        }

        if ($action === 'add_year') {
            $stmt = $pdo->prepare('INSERT INTO academic_years (school_id,name,start_date,end_date,is_current) VALUES (?,?,?,?,?)');
            $stmt->execute([$schoolId, post('name'), post('start_date') ?: null, post('end_date') ?: null, isset($_POST['is_current']) ? 1 : 0]);
            if (isset($_POST['is_current'])) $pdo->prepare('UPDATE academic_years SET is_current=0 WHERE school_id=? AND id<>?')->execute([$schoolId, $pdo->lastInsertId()]);
            Audit::log('academic_year.create','academic_year',$pdo->lastInsertId(),null,$_POST); $message='Academic year added.';
        }
        if ($action === 'set_current_year') {
            $pdo->prepare('UPDATE academic_years SET is_current=0 WHERE school_id=?')->execute([$schoolId]);
            $pdo->prepare('UPDATE academic_years SET is_current=1 WHERE school_id=? AND id=?')->execute([$schoolId, (int)$_POST['id']]);
            Audit::log('academic_year.set_current','academic_year',(int)$_POST['id']); $message='Current academic year updated.';
        }
        if ($action === 'delete_year') {
            $pdo->prepare('DELETE FROM academic_years WHERE school_id=? AND id=?')->execute([$schoolId, (int)$_POST['id']]);
            Audit::log('academic_year.delete','academic_year',(int)$_POST['id']); $message='Academic year deleted.';
        }
        if ($action === 'add_term') {
            $stmt = $pdo->prepare('INSERT INTO terms (academic_year_id,name,start_date,end_date,is_current) VALUES (?,?,?,?,?)');
            $stmt->execute([(int)$_POST['academic_year_id'], post('name'), post('start_date') ?: null, post('end_date') ?: null, isset($_POST['is_current']) ? 1 : 0]);
            if (isset($_POST['is_current'])) $pdo->prepare('UPDATE terms SET is_current=0 WHERE academic_year_id=? AND id<>?')->execute([(int)$_POST['academic_year_id'], $pdo->lastInsertId()]);
            Audit::log('term.create','term',$pdo->lastInsertId(),null,$_POST); $message='Term added.';
        }
        if ($action === 'set_current_term') {
            $yearId = (int)$_POST['academic_year_id']; $id=(int)$_POST['id'];
            $pdo->prepare('UPDATE terms SET is_current=0 WHERE academic_year_id=?')->execute([$yearId]);
            $pdo->prepare('UPDATE terms SET is_current=1 WHERE id=?')->execute([$id]);
            Audit::log('term.set_current','term',$id); $message='Current term updated.';
        }
        if ($action === 'delete_term') {
            $pdo->prepare('DELETE FROM terms WHERE id=?')->execute([(int)$_POST['id']]);
            Audit::log('term.delete','term',(int)$_POST['id']); $message='Term deleted.';
        }
        if ($action === 'add_class') {
            $stmt = $pdo->prepare('INSERT INTO classes (school_id,name,level) VALUES (?,?,?)');
            $stmt->execute([$schoolId, post('name'), post('level') ?: null]);
            Audit::log('class.create','class',$pdo->lastInsertId(),null,$_POST); $message='Class added.';
        }
        if ($action === 'delete_class') {
            $pdo->prepare('DELETE FROM classes WHERE school_id=? AND id=?')->execute([$schoolId, (int)$_POST['id']]);
            Audit::log('class.delete','class',(int)$_POST['id']); $message='Class deleted.';
        }
        if ($action === 'add_stream') {
            $stmt = $pdo->prepare('INSERT INTO streams (class_id,name) VALUES (?,?)');
            $stmt->execute([(int)$_POST['class_id'], post('name')]);
            Audit::log('stream.create','stream',$pdo->lastInsertId(),null,$_POST); $message='Stream added.';
        }
        if ($action === 'delete_stream') {
            $pdo->prepare('DELETE FROM streams WHERE id=?')->execute([(int)$_POST['id']]);
            Audit::log('stream.delete','stream',(int)$_POST['id']); $message='Stream deleted.';
        }
        if ($action === 'add_subject') {
            $stmt = $pdo->prepare('INSERT INTO subjects (school_id,name,code) VALUES (?,?,?)');
            $stmt->execute([$schoolId, post('name'), post('code') ?: null]);
            Audit::log('subject.create','subject',$pdo->lastInsertId(),null,$_POST); $message='Subject added.';
        }
        if ($action === 'delete_subject') {
            $pdo->prepare('DELETE FROM subjects WHERE school_id=? AND id=?')->execute([$schoolId, (int)$_POST['id']]);
            Audit::log('subject.delete','subject',(int)$_POST['id']); $message='Subject deleted.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$years = $classes = $subjects = $streams = $terms = [];
if ($pdo && DB::hasTable('academic_years')) {
    $years = $pdo->prepare('SELECT * FROM academic_years WHERE school_id=? ORDER BY is_current DESC, start_date DESC, id DESC');
    $years->execute([$schoolId]); $years = $years->fetchAll();
    $classes = $pdo->prepare('SELECT * FROM classes WHERE school_id=? ORDER BY name'); $classes->execute([$schoolId]); $classes = $classes->fetchAll();
    $subjects = $pdo->prepare('SELECT * FROM subjects WHERE school_id=? ORDER BY name'); $subjects->execute([$schoolId]); $subjects = $subjects->fetchAll();
    $streams = $pdo->query('SELECT s.*, c.name AS class_name FROM streams s JOIN classes c ON c.id=s.class_id ORDER BY c.name, s.name')->fetchAll();
    $terms = $pdo->query('SELECT t.*, y.name AS year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY y.is_current DESC, t.is_current DESC, t.start_date DESC')->fetchAll();
}
$isAdmin = in_array(Auth::user()['role_name'] ?? '', ['super_admin','school_admin','head_teacher'], true);
?>
<div class="card">
  <div class="section-title"><h2>Academic Setup</h2><span class="badge <?= $isAdmin ? 'ok':'warn' ?>"><?= $isAdmin ? 'Admin access' : 'Read only' ?></span></div>
  <p class="muted">Configure academic years, terms, classes, streams and subjects. These are used by admissions, attendance, academics and reporting.</p>
  <?php if ($message): ?><div class="empty" style="border-color:#b7dfc6;color:#1b7f4a;background:#f3fbf6;"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="empty" style="border-color:#f1c0c0;color:#9b1c1c;background:#fff7f7;"><?= e($error) ?></div><?php endif; ?>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Academic Years</h3>
    <?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> class="grid" style="margin-bottom:12px;"><input type="hidden" name="action" value="add_year">
      <label>Name<input class="input" name="name" placeholder="2026" required></label>
      <div class="grid grid-2"><label>Start<input class="input" type="date" name="start_date"></label><label>End<input class="input" type="date" name="end_date"></label></div>
      <label><input type="checkbox" name="is_current"> Set as current</label>
      <button class="btn" type="submit">Add Year</button>
    </form><?php endif; ?>
    <table class="compact"><thead><tr><th>Year</th><th>Dates</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($years as $y): ?><tr>
        <td><?= e($y['name']) ?></td><td><?= e(($y['start_date']?:'-').' to '.($y['end_date']?:'-')) ?></td>
        <td><?= $y['is_current'] ? '<span class="badge ok">Current</span>' : '' ?></td>
        <td><?php if ($isAdmin): ?>
          <?php if (!$y['is_current']): ?><form method="post"><?= CSRF::field() ?> style="display:inline"><input type="hidden" name="action" value="set_current_year"><input type="hidden" name="id" value="<?= e($y['id']) ?>"><button class="btn secondary" type="submit">Set current</button></form><?php endif; ?>
          <form method="post"><?= CSRF::field() ?> style="display:inline" onsubmit="return confirm('Delete year?')"><input type="hidden" name="action" value="delete_year"><input type="hidden" name="id" value="<?= e($y['id']) ?>"><button class="btn secondary" type="submit">Delete</button></form>
        <?php endif; ?></td>
      </tr><?php endforeach; if (!$years): ?><tr><td colspan="4" class="muted">No academic years yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>

  <div class="card">
    <h3>Terms</h3>
    <?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> class="grid" style="margin-bottom:12px;"><input type="hidden" name="action" value="add_term">
      <label>Academic year<select class="input" name="academic_year_id" required><?php foreach ($years as $y): ?><option value="<?= e($y['id']) ?>"><?= e($y['name']) ?></option><?php endforeach; ?></select></label>
      <label>Name<input class="input" name="name" placeholder="Term 1" required></label>
      <div class="grid grid-2"><label>Start<input class="input" type="date" name="start_date"></label><label>End<input class="input" type="date" name="end_date"></label></div>
      <label><input type="checkbox" name="is_current"> Set as current</label>
      <button class="btn" type="submit">Add Term</button>
    </form><?php endif; ?>
    <table class="compact"><thead><tr><th>Term</th><th>Year</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($terms as $t): ?><tr>
        <td><?= e($t['name']) ?></td><td><?= e($t['year_name']) ?></td><td><?= $t['is_current'] ? '<span class="badge ok">Current</span>' : '' ?></td>
        <td><?php if ($isAdmin): ?>
          <?php if (!$t['is_current']): ?><form method="post"><?= CSRF::field() ?> style="display:inline"><input type="hidden" name="action" value="set_current_term"><input type="hidden" name="id" value="<?= e($t['id']) ?>"><input type="hidden" name="academic_year_id" value="<?= e($t['academic_year_id']) ?>"><button class="btn secondary" type="submit">Set current</button></form><?php endif; ?>
          <form method="post"><?= CSRF::field() ?> style="display:inline" onsubmit="return confirm('Delete term?')"><input type="hidden" name="action" value="delete_term"><input type="hidden" name="id" value="<?= e($t['id']) ?>"><button class="btn secondary" type="submit">Delete</button></form>
        <?php endif; ?></td>
      </tr><?php endforeach; if (!$terms): ?><tr><td colspan="4" class="muted">No terms yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<div class="grid grid-2">
  <div class="card">
    <h3>Classes & Streams</h3>
    <?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> class="grid" style="margin-bottom:12px;"><input type="hidden" name="action" value="add_class">
      <label>Class name<input class="input" name="name" placeholder="P1 / Grade 1" required></label>
      <label>Level<input class="input" name="level" placeholder="Primary"></label>
      <button class="btn" type="submit">Add Class</button>
    </form><?php endif; ?>
    <table class="compact"><thead><tr><th>Class</th><th>Level</th><th></th></tr></thead><tbody>
      <?php foreach ($classes as $c): ?><tr><td><?= e($c['name']) ?></td><td><?= e($c['level']?:'-') ?></td><td><?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> style="display:inline" onsubmit="return confirm('Delete class?')"><input type="hidden" name="action" value="delete_class"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn secondary" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; if (!$classes): ?><tr><td colspan="3" class="muted">No classes yet.</td></tr><?php endif; ?></tbody></table>
    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
    <?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> class="grid" style="margin-bottom:12px;"><input type="hidden" name="action" value="add_stream">
      <label>Class<select class="input" name="class_id" required><?php foreach ($classes as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <label>Stream name<input class="input" name="name" placeholder="A / East" required></label>
      <button class="btn" type="submit">Add Stream</button>
    </form><?php endif; ?>
    <table class="compact"><thead><tr><th>Stream</th><th>Class</th><th></th></tr></thead><tbody>
      <?php foreach ($streams as $s): ?><tr><td><?= e($s['name']) ?></td><td><?= e($s['class_name']) ?></td><td><?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> style="display:inline" onsubmit="return confirm('Delete stream?')"><input type="hidden" name="action" value="delete_stream"><input type="hidden" name="id" value="<?= e($s['id']) ?>"><button class="btn secondary" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; if (!$streams): ?><tr><td colspan="3" class="muted">No streams yet.</td></tr><?php endif; ?></tbody></table>
  </div>
  <div class="card">
    <h3>Subjects</h3>
    <?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> class="grid" style="margin-bottom:12px;"><input type="hidden" name="action" value="add_subject">
      <label>Subject name<input class="input" name="name" placeholder="Mathematics" required></label>
      <label>Code<input class="input" name="code" placeholder="MTH"></label>
      <button class="btn" type="submit">Add Subject</button>
    </form><?php endif; ?>
    <table class="compact"><thead><tr><th>Subject</th><th>Code</th><th></th></tr></thead><tbody>
      <?php foreach ($subjects as $sub): ?><tr><td><?= e($sub['name']) ?></td><td><?= e($sub['code']?:'-') ?></td><td><?php if ($isAdmin): ?><form method="post"><?= CSRF::field() ?> style="display:inline" onsubmit="return confirm('Delete subject?')"><input type="hidden" name="action" value="delete_subject"><input type="hidden" name="id" value="<?= e($sub['id']) ?>"><button class="btn secondary" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; if (!$subjects): ?><tr><td colspan="3" class="muted">No subjects yet.</td></tr><?php endif; ?></tbody></table>
  </div>
</div>



