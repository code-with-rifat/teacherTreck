<?php
/**
 * Classes module — create + list (branch only)
 * One day: same teacher + subject + class + lecture → up to 4 time slots
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';
ensure_class_curriculum_schema();

$user = require_login(['branch_manager']);
$pdo = db();
$branch = manager_branch($pdo, $user);
manager_require_setup($branch);

$allowedSlots = array_keys(medico_time_slots());
$subjects = medico_subjects();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_class') {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $classDate = (string) ($_POST['class_date'] ?? '');
    $category = (string) ($_POST['course_category'] ?? '');
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $lectureNo = (int) ($_POST['lecture_no'] ?? 0);
    $slots = $_POST['time_slots'] ?? [];
    if (!is_array($slots)) {
        $slots = [$slots];
    }
    $slots = array_values(array_unique(array_filter($slots, static fn ($s) => in_array($s, $allowedSlots, true))));

    if (!$teacherId || !$classDate || !in_array($category, ['1st_timer', '2nd_timer'], true)) {
        flash('error', 'Teacher, date এবং class type দিতে হবে।');
        redirect('/manager/classes.php?new=1');
    }
    if ($subject === '' || !in_array($subject, $subjects, true)) {
        flash('error', 'Subject select করুন।');
        redirect('/manager/classes.php?new=1');
    }
    if ($lectureNo < 1 || $lectureNo > 200) {
        flash('error', 'Lecture number 1–200 দিতে হবে।');
        redirect('/manager/classes.php?new=1');
    }
    if (!$slots) {
        flash('error', 'কমপক্ষে একটা time schedule select করুন (৭ / ১০ / ১ / ৪)।');
        redirect('/manager/classes.php?new=1');
    }
    if (count($slots) > 4) {
        flash('error', 'একদিনে সর্বোচ্চ ৪টা schedule।');
        redirect('/manager/classes.php?new=1');
    }

    $ok = $pdo->prepare(
        "SELECT t.id FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND u.status = 'active'"
    );
    $ok->execute([$teacherId]);
    if (!$ok->fetch()) {
        flash('error', 'Teacher not found or inactive.');
        redirect('/manager/classes.php?new=1');
    }

    $dup = $pdo->prepare(
        'SELECT time_slot FROM classes
         WHERE branch_id = ? AND teacher_id = ? AND class_date = ? AND time_slot = ?
           AND status <> \'cancelled\''
    );
    $insert = $pdo->prepare(
        'INSERT INTO classes (branch_id, teacher_id, class_date, time_slot, course_category, subject, lecture_no, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $created = 0;
    $skipped = [];
    $firstId = null;
    foreach ($slots as $slot) {
        $dup->execute([(int) $branch['id'], $teacherId, $classDate, $slot]);
        if ($dup->fetch()) {
            $skipped[] = format_time($slot);
            continue;
        }
        $insert->execute([
            (int) $branch['id'],
            $teacherId,
            $classDate,
            $slot,
            $category,
            $subject,
            $lectureNo,
            (int) $user['id'],
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($firstId === null) {
            $firstId = $id;
        }
        $created++;
    }

    if ($created === 0) {
        flash('error', 'কোনো schedule তৈরি হয়নি — সব slot আগে থেকেই assign আছে: ' . implode(', ', $skipped));
        redirect('/manager/classes.php?new=1');
    }

    // Notify teacher as soon as classes are assigned
    try {
        $tu = $pdo->prepare('SELECT user_id, full_name FROM teachers WHERE id = ?');
        $tu->execute([$teacherId]);
        $tRow = $tu->fetch();
        if ($tRow && (int) $tRow['user_id'] > 0) {
            $slotLabels = array_map(static fn ($s) => format_time($s), $slots);
            $when = date('j M Y', strtotime($classDate));
            notify_user(
                (int) $tRow['user_id'],
                'New class assigned · ' . $subject,
                $branch['name'] . ' · ' . $when . ' · ' . implode(', ', $slotLabels)
                    . ' · Lec ' . $lectureNo,
                'class_assigned',
                'class',
                $firstId
            );
            notify_admins(
                'Class assigned · ' . $subject,
                $branch['name'] . ' · ' . ($tRow['full_name'] ?? 'Teacher') . ' · ' . $when
                    . ' · ' . implode(', ', $slotLabels) . ' · Lec ' . $lectureNo,
                'class_assigned',
                'class',
                $firstId
            );
        }
    } catch (Throwable $e) {
        // soft-fail
    }

    $msg = $created . ' schedule assign হয়েছে (' . $subject . ' · Lec ' . $lectureNo . ').';
    if ($skipped) {
        $msg .= ' Skip: ' . implode(', ', $skipped);
    }
    flash('success', $msg);
    redirect($firstId ? '/manager/class.php?id=' . $firstId : '/manager/classes.php');
}

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d', strtotime('+14 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d', strtotime('+14 days'));
}

$countSt = $pdo->prepare(
    'SELECT COUNT(*) FROM classes c
     WHERE c.branch_id = ? AND c.class_date BETWEEN ? AND ?'
);
$countSt->execute([(int) $branch['id'], $from, $to]);
$classTotal = (int) $countSt->fetchColumn();
$p = paginate_request(25);
$meta = paginate_meta($classTotal, $p);

$list = $pdo->prepare(
    'SELECT c.*, t.full_name AS teacher_name, t.phone AS teacher_phone,
            cs.check_in_at, cs.student_count_manager, cs.student_count_teacher,
            cs.manager_class_start_at, cs.manager_class_end_at,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat
     FROM classes c
     JOIN teachers t ON t.id = c.teacher_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     WHERE c.branch_id = ? AND c.class_date BETWEEN ? AND ?
     ORDER BY c.class_date ASC, c.time_slot ASC
     LIMIT ' . (int) $meta['per'] . ' OFFSET ' . (int) $meta['offset']
);
$list->execute([(int) $branch['id'], $from, $to]);
$classes = $list->fetchAll();

$teachers = $pdo->query(
    "SELECT t.id, t.full_name, t.phone, t.medical_college
     FROM teachers t JOIN users u ON u.id = t.user_id
     WHERE u.status = 'active' ORDER BY t.full_name"
)->fetchAll();

$showForm = isset($_GET['new']) || isset($_GET['create']);
$preselectTeacher = (int) ($_GET['teacher_id'] ?? 0);
$preselectDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $preselectDate)) {
    $preselectDate = date('Y-m-d');
}

$nav = manager_nav('classes');
extract($nav);
$pageTitle = 'Classes';
$pageSub = 'Create & assign · ' . $branch['name'];
$displayName = $branch['name'];
$topActions = $showForm
    ? '<a class="btn btn-secondary btn-sm" href="/manager/classes.php" style="width:auto">Back to list</a>'
    : '<a class="btn btn-primary btn-sm" href="/manager/classes.php?new=1" style="width:auto">+ Assign class</a>';
require __DIR__ . '/../includes/app_header.php';
?>

<?php if ($showForm): ?>
        <section class="panel" id="create">
          <div class="panel-header"><h3>Assign class to teacher</h3></div>
          <div class="panel-body">
            <p style="font-size:.88rem;color:var(--slate-500);margin-bottom:1rem">
              একদিনে একই subject + class + lecture — teacher কে ৪টা schedule (৭ / ১০ / ১ / ৪) পর্যন্ত একসাথে assign করা যায়।
              Teacher calendar-এও সেইভাবে দেখবে: আগে ১০টার, তারপর ১টার…
            </p>
            <form method="post">
              <input type="hidden" name="action" value="create_class" />
              <div class="form-group">
                <label>Teacher</label>
                <select name="teacher_id" required>
                  <option value="">Select teacher…</option>
                  <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= $preselectTeacher === (int) $t['id'] ? 'selected' : '' ?>>
                      <?= h($t['full_name']) ?> · <?= h($t['phone']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($preselectTeacher): ?>
                  <div style="margin-top:.45rem;font-size:.85rem">
                    <a href="/manager/teacher.php?id=<?= $preselectTeacher ?>">Teacher profile দেখুন →</a>
                  </div>
                <?php endif; ?>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Subject</label>
                  <select name="subject" required>
                    <option value="">Select subject…</option>
                    <?php foreach ($subjects as $s): ?>
                      <option value="<?= h($s) ?>"><?= h($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Class type</label>
                  <select name="course_category" required>
                    <option value="1st_timer">1st Timer</option>
                    <option value="2nd_timer">2nd Timer</option>
                  </select>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Lecture number</label>
                  <input type="number" name="lecture_no" min="1" max="200" value="1" required />
                </div>
                <div class="form-group">
                  <label>Class date</label>
                  <input type="date" name="class_date" required value="<?= h($preselectDate) ?>" />
                </div>
              </div>
              <div class="form-group">
                <label>Day schedules (একসাথে একাধিক select)</label>
                <div class="slot-checks">
                  <?php foreach (medico_time_slots() as $value => $label): ?>
                    <label class="slot-check">
                      <input type="checkbox" name="time_slots[]" value="<?= h($value) ?>"
                        <?= $value === '10:00:00' ? 'checked' : '' ?> />
                      <span><?= h($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <button class="btn btn-primary" type="submit" style="width:auto" <?= $teachers ? '' : 'disabled' ?>>
                Assign selected schedules
              </button>
            </form>
          </div>
        </section>
<?php else: ?>
        <section class="panel panel-tight">
          <div class="list-toolbar">
            <div>
              <h3>Branch classes</h3>
              <span class="list-count"><?= (int) $classTotal ?> in range</span>
            </div>
            <form method="get" class="filter-bar">
              <label>
                <span>From</span>
                <input type="date" name="from" value="<?= h($from) ?>" />
              </label>
              <label>
                <span>To</span>
                <input type="date" name="to" value="<?= h($to) ?>" />
              </label>
              <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
            </form>
          </div>
          <div class="table-wrap">
            <table class="data data-compact data-clean">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Class</th>
                  <th>Status</th>
                  <th class="col-actions"></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$classes): ?>
                <tr><td colspan="4"><div class="empty-state"><strong>No classes in range</strong></div></td></tr>
              <?php else: foreach ($classes as $c):
                  $onSite = !empty($c['check_in_at']);
                  $hasReview = $c['count_best'] !== null || $c['count_good'] !== null;
              ?>
                <tr>
                  <td class="cell-when">
                    <strong><?= h(format_time($c['time_slot'])) ?></strong>
                    <span><?= h(date('j M', strtotime($c['class_date']))) ?></span>
                  </td>
                  <td>
                    <div class="cell-class">
                      <strong><?= h($c['teacher_name']) ?></strong>
                      <span>
                        <?= !empty($c['subject']) ? h($c['subject']) : 'Subject TBD' ?>
                        · <?= ($c['course_category'] ?? '') === '2nd_timer' ? '2nd' : '1st' ?>
                        <?php if (!empty($c['lecture_no'])): ?> · L<?= (int) $c['lecture_no'] ?><?php endif; ?>
                      </span>
                    </div>
                  </td>
                  <td><?= badge($c['status']) ?></td>
                  <td class="col-actions">
                    <details class="menu-dots">
                      <summary aria-label="More">⋯</summary>
                      <div class="menu-panel">
                        <div class="meta-line">
                          <strong><?= h($c['teacher_name']) ?></strong>
                          <?= h($c['teacher_phone']) ?><br>
                          <?= h(date('j M Y', strtotime($c['class_date']))) ?>
                          · <?= h(format_time($c['time_slot'])) ?>
                        </div>
                        <div class="meta-line">
                          <strong>Subject</strong>
                          <?= !empty($c['subject']) ? h($c['subject']) : '—' ?>
                          · <?= ($c['course_category'] ?? '') === '2nd_timer' ? '2nd Timer' : '1st Timer' ?>
                          <?php if (!empty($c['lecture_no'])): ?> · Lecture <?= (int) $c['lecture_no'] ?><?php endif; ?>
                        </div>
                        <div class="meta-line">
                          <strong>Progress</strong>
                          On site: <?= $onSite ? 'Yes' : 'No' ?><br>
                          Your times:
                          <?= format_clock($c['manager_class_start_at'] ?? null) ?>
                          →
                          <?= format_clock($c['manager_class_end_at'] ?? null) ?><br>
                          Review:
                          <?php if ($hasReview): ?>
                            B<?= (int) $c['count_best'] ?> · G<?= (int) $c['count_good'] ?> · Bad<?= (int) $c['count_bad'] ?> · R<?= (int) $c['count_repeat'] ?>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </div>
                        <div class="row-action" style="display:flex;gap:.4rem;flex-wrap:wrap">
                          <a class="btn btn-primary btn-sm" href="/manager/class.php?id=<?= (int) $c['id'] ?>">Track class</a>
                          <a class="btn btn-secondary btn-sm" href="/manager/teacher.php?id=<?= (int) $c['teacher_id'] ?>">View profile</a>
                        </div>
                      </div>
                    </details>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <?= render_pager($meta) ?>
        </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
