<?php
/**
 * Teacher class room — start / end / notes + branch check-out
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/teacher.php';
$user = require_login(['teacher']);
ensure_class_curriculum_schema();
ensure_teacher_flow_schema();

$pdo = db();
$teacherStmt = $pdo->prepare('SELECT * FROM teachers WHERE user_id = ?');
$teacherStmt->execute([(int) $user['id']]);
$teacher = $teacherStmt->fetch();
if (!$teacher) {
    flash('error', 'Teacher profile missing.');
    redirect('/teacher-traking/logout.php');
}
$teacherId = (int) $teacher['id'];
$teacherName = (string) $teacher['full_name'];

$classId = (int) ($_GET['id'] ?? $_POST['class_id'] ?? 0);
$classStmt = $pdo->prepare(
    'SELECT c.*, b.name AS branch_name, b.city, b.id AS branch_id,
            cs.id AS session_id, cs.check_in_at AS session_check_in, cs.check_out_at AS session_check_out,
            cs.student_count_teacher,
            cs.teacher_class_start_at, cs.teacher_class_end_at,
            cs.teacher_notes, cs.teacher_start_notes
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     WHERE c.id = ? AND c.teacher_id = ?'
);
$classStmt->execute([$classId, $teacherId]);
$class = $classStmt->fetch();
if (!$class) {
    flash('error', 'Class not found.');
    redirect('/teacher-traking/teacher/dashboard.php');
}

$day = (string) $class['class_date'];
$branchId = (int) $class['branch_id'];
$dashUrl = '/teacher-traking/teacher/dashboard.php?date=' . urlencode($day);
$classUrl = '/teacher-traking/teacher/class.php?id=' . $classId;

$visit = teacher_branch_day($pdo, $teacherId, $branchId, $day);
$onSite = teacher_visit_on_site($visit);
$everIn = !empty($visit['check_in_at']);

$prevStmt = $pdo->prepare(
    'SELECT c.id, c.time_slot, cs.teacher_class_end_at
     FROM classes c
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     WHERE c.teacher_id = ? AND c.class_date = ? AND (c.time_slot < ? OR (c.time_slot = ? AND c.id < ?))
     ORDER BY c.time_slot ASC, c.id ASC'
);
$prevStmt->execute([$teacherId, $day, $class['time_slot'], $class['time_slot'], $classId]);
$prevClasses = $prevStmt->fetchAll();

$tStart = $class['teacher_class_start_at'] ?? null;
$tEnd = $class['teacher_class_end_at'] ?? null;
$done = !empty($tEnd);
$live = !empty($tStart) && !$done;

// ---- Branch check-in (presence only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'branch_check_in') {
    if ($onSite) {
        flash('info', 'Already checked in.');
        redirect($classUrl);
    }
    if (!$everIn) {
        $pdo->prepare(
            'UPDATE teacher_branch_days SET check_in_at = NOW(), last_check_in_at = NOW()
             WHERE teacher_id = ? AND branch_id = ? AND visit_date = ?'
        )->execute([$teacherId, $branchId, $day]);
    } else {
        $pdo->prepare(
            'UPDATE teacher_branch_days SET last_check_in_at = NOW()
             WHERE teacher_id = ? AND branch_id = ? AND visit_date = ?'
        )->execute([$teacherId, $branchId, $day]);
    }
    notify_branch_visit($pdo, $branchId, $teacherName, 'check_in', $day);
    flash('success', $everIn ? 'Checked in again.' : 'Branch check-in saved.');
    redirect($classUrl);
}

// ---- Branch check-out ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'branch_check_out') {
    if (!$onSite) {
        flash('error', 'Check in first — then check out.');
        redirect($classUrl);
    }
    $pdo->prepare(
        'UPDATE teacher_branch_days SET check_out_at = NOW()
         WHERE teacher_id = ? AND branch_id = ? AND visit_date = ?'
    )->execute([$teacherId, $branchId, $day]);
    notify_branch_visit($pdo, $branchId, $teacherName, 'check_out', $day);
    flash('success', 'Checked out of branch.');
    redirect($classUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'teacher_start') {
    if ($done || $live) {
        flash('error', $done ? 'Class already finished.' : 'Class already started.');
        redirect($classUrl);
    }
    foreach ($prevClasses as $prev) {
        if (empty($prev['teacher_class_end_at'])) {
            flash('error', 'Age er class (' . format_time($prev['time_slot'] ?? null) . ') ses korun, tarpor eita start korun.');
            redirect($classUrl);
        }
    }

    $startAt = parse_local_datetime($_POST['teacher_class_start_at'] ?? '') ?: date('Y-m-d H:i:s');
    $startNote = trim((string) ($_POST['teacher_start_notes'] ?? ''));
    $mgrMsg = trim((string) ($_POST['manager_message'] ?? ''));
    $session = session_row_for_class($pdo, $classId);

    $pdo->prepare(
        'UPDATE class_sessions SET teacher_class_start_at = ?, teacher_start_notes = ?, teacher_times_saved_at = NOW()
         WHERE id = ?'
    )->execute([$startAt, $startNote !== '' ? $startNote : null, (int) $session['id']]);
    $pdo->prepare("UPDATE classes SET status = 'in_progress' WHERE id = ?")->execute([$classId]);

    notify_teacher_class_action($pdo, $classId, 'class_start', $teacherName);
    if ($startNote !== '') {
        notify_manager_message($pdo, $classId, $teacherName, 'Start note: ' . $startNote);
    }
    if ($mgrMsg !== '') {
        notify_manager_message($pdo, $classId, $teacherName, $mgrMsg);
    }

    flash('success', 'Class started. Manager notified.');
    redirect($classUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'teacher_end') {
    if (!$live) {
        flash('error', empty($tStart) ? 'Start the class first.' : 'Class already ended.');
        redirect($classUrl);
    }

    $endAt = parse_local_datetime($_POST['teacher_class_end_at'] ?? '') ?: date('Y-m-d H:i:s');
    $count = $_POST['student_count_teacher'] !== '' ? (int) $_POST['student_count_teacher'] : null;
    $endNote = trim((string) ($_POST['teacher_notes'] ?? ''));
    $mgrMsg = trim((string) ($_POST['manager_message'] ?? ''));
    $session = session_row_for_class($pdo, $classId);

    $pdo->prepare(
        'UPDATE class_sessions SET teacher_class_end_at = ?, student_count_teacher = ?,
         teacher_notes = ?, teacher_times_saved_at = NOW() WHERE id = ?'
    )->execute([$endAt, $count, $endNote !== '' ? $endNote : null, (int) $session['id']]);
    $pdo->prepare("UPDATE classes SET status = 'completed' WHERE id = ?")->execute([$classId]);

    notify_teacher_class_action($pdo, $classId, 'class_end', $teacherName);
    if ($endNote !== '') {
        notify_manager_message($pdo, $classId, $teacherName, 'End note: ' . $endNote);
    }
    if ($mgrMsg !== '') {
        notify_manager_message($pdo, $classId, $teacherName, $mgrMsg);
    }

    flash('success', 'Class ended. Please submit the branch review for Admin.');
    redirect('/teacher-traking/teacher/review.php?class_id=' . $classId);
}

$classStmt->execute([$classId, $teacherId]);
$class = $classStmt->fetch();
$visit = teacher_branch_day($pdo, $teacherId, $branchId, $day);
$onSite = teacher_visit_on_site($visit);
$everIn = !empty($visit['check_in_at']);
$tStart = $class['teacher_class_start_at'] ?? null;
$tEnd = $class['teacher_class_end_at'] ?? null;
$done = !empty($tEnd);
$live = !empty($tStart) && !$done;
$startNote = trim((string) ($class['teacher_start_notes'] ?? ''));
$endNote = trim((string) ($class['teacher_notes'] ?? ''));

$revSt = $pdo->prepare('SELECT id, has_issue_flag, submitted_at FROM class_reviews WHERE class_id = ? LIMIT 1');
$revSt->execute([$classId]);
$branchReview = $revSt->fetch() ?: null;

$blockedByPrev = false;
$blockSlot = null;
if (!$tStart) {
    foreach ($prevClasses as $prev) {
        if (empty($prev['teacher_class_end_at'])) {
            $blockedByPrev = true;
            $blockSlot = format_time($prev['time_slot'] ?? null);
            break;
        }
    }
}

$metaBits = [];
if (!empty($class['subject'])) {
    $metaBits[] = $class['subject'];
}
$metaBits[] = ($class['course_category'] ?? '') === '2nd_timer' ? '2nd timer' : '1st timer';
if (!empty($class['lecture_no'])) {
    $metaBits[] = 'Lec ' . (int) $class['lecture_no'];
}

$durationMin = null;
if ($tStart && $tEnd) {
    $durationMin = max(0, (int) round((strtotime($tEnd) - strtotime($tStart)) / 60));
}

$nav = teacher_nav('dashboard', $teacherName);
extract($nav);
$pageTitle = format_time($class['time_slot']) . ' · ' . ($class['subject'] ?: 'Class');
$pageSub = $class['branch_name'] . ' · ' . date('j M Y', strtotime($day));
$hidePageHead = true;
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <div>
            <a class="dg-back" href="<?= h($dashUrl) ?>">← Dashboard</a>
            <h1 class="dg-hello" style="margin-top:.35rem"><?= h(format_time($class['time_slot'])) ?> · <?= h($class['subject'] ?: 'Class') ?></h1>
            <p class="dg-class-sub"><?= h(implode(' · ', $metaBits)) ?> · <?= h($class['branch_name']) ?> · <?= h(date('j M Y', strtotime($day))) ?></p>
          </div>
          <span class="dg-slot-badge<?= $live ? ' is-accent' : '' ?><?= $done ? ' is-ok' : '' ?>">
            <?php if ($done): ?>Done
            <?php elseif ($live): ?>Live
            <?php elseif ($blockedByPrev): ?>Wait
            <?php else: ?>Ready
            <?php endif; ?>
          </span>
        </div>

        <div class="dg-checkbar<?= $onSite ? ' is-in' : ($everIn ? ' is-out' : '') ?>" style="max-width:720px">
          <div class="dg-checkbar-text">
            <strong><?= h($class['branch_name']) ?> · presence</strong>
            <span>
              <?php if (!$everIn): ?>
                Branch e gele check in korun (class start er jonno dorkar nai)
              <?php elseif ($onSite): ?>
                On site · first in <?= h(format_clock($visit['check_in_at'] ?? null)) ?>
              <?php else: ?>
                Out <?= h(format_clock($visit['check_out_at'] ?? null)) ?> · first in was <?= h(format_clock($visit['check_in_at'] ?? null)) ?>
              <?php endif; ?>
            </span>
          </div>
          <?php if (!$onSite): ?>
            <form method="post">
              <input type="hidden" name="action" value="branch_check_in" />
              <button type="submit" class="btn btn-primary btn-sm" style="width:auto"><?= $everIn ? 'Check in again' : 'Check in' ?></button>
            </form>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="action" value="branch_check_out" />
              <button type="submit" class="btn btn-secondary btn-sm" style="width:auto">Check out</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="dg-class-stats">
          <div><span>Start</span><strong><?= h(format_clock($tStart)) ?></strong></div>
          <div><span>End</span><strong><?= h(format_clock($tEnd)) ?></strong></div>
          <div><span>Students</span><strong><?= $class['student_count_teacher'] !== null ? (int) $class['student_count_teacher'] : '—' ?></strong></div>
          <div><span><?= $durationMin !== null ? 'Duration' : 'Status' ?></span>
            <strong><?= $durationMin !== null ? $durationMin . ' min' : h(ucfirst(str_replace('_', ' ', (string) $class['status']))) ?></strong>
          </div>
        </div>

        <?php if ($done || $startNote !== '' || $endNote !== '' || $live): ?>
          <section class="dg-card dg-class-room dg-detail-card">
            <div class="dg-card-head"><h3>Class details &amp; notes</h3></div>
            <div class="dg-detail-grid">
              <div class="dg-detail-row">
                <span>Scheduled</span>
                <strong><?= h(format_time($class['time_slot'])) ?> · <?= h(date('j M Y', strtotime($day))) ?></strong>
              </div>
              <div class="dg-detail-row">
                <span>Branch</span>
                <strong><?= h($class['branch_name']) ?><?= !empty($class['city']) ? ' · ' . h($class['city']) : '' ?></strong>
              </div>
              <div class="dg-detail-row">
                <span>Subject</span>
                <strong><?= h(implode(' · ', $metaBits)) ?></strong>
              </div>
              <div class="dg-detail-row">
                <span>My start → end</span>
                <strong><?= h(format_clock($tStart)) ?> → <?= h(format_clock($tEnd)) ?></strong>
              </div>
              <?php if ($class['student_count_teacher'] !== null): ?>
                <div class="dg-detail-row">
                  <span>Attendance</span>
                  <strong><?= (int) $class['student_count_teacher'] ?> students</strong>
                </div>
              <?php endif; ?>
            </div>

            <div class="dg-notes-panel">
              <div class="dg-note-box">
                <h4>Start note</h4>
                <?php if ($startNote !== ''): ?>
                  <p><?= nl2br(h($startNote)) ?></p>
                <?php else: ?>
                  <p class="is-empty">No start note written.</p>
                <?php endif; ?>
              </div>
              <div class="dg-note-box">
                <h4>End note</h4>
                <?php if ($endNote !== ''): ?>
                  <p><?= nl2br(h($endNote)) ?></p>
                <?php else: ?>
                  <p class="is-empty"><?= $done ? 'No end note written.' : ($live ? 'End korar somoy note likhte parben.' : 'Start er por end note asbe.') ?></p>
                <?php endif; ?>
              </div>
            </div>
          </section>
        <?php endif; ?>

        <section class="dg-card dg-class-room">
          <?php if ($done): ?>
            <div class="dg-card-head"><h3>Class finished</h3></div>
            <p class="dg-slot-hint" style="margin:0">
              <?= h(format_clock($tStart)) ?> → <?= h(format_clock($tEnd)) ?>
              <?php if ($class['student_count_teacher'] !== null): ?> · <?= (int) $class['student_count_teacher'] ?> students<?php endif; ?>
              <?php if ($durationMin !== null): ?> · <?= $durationMin ?> min<?php endif; ?>
            </p>

            <div class="dg-review-cta<?= $branchReview ? ' is-done' : '' ?>">
              <?php if ($branchReview): ?>
                <div>
                  <strong>Branch review submitted</strong>
                  <span>Admin details-এ দেখতে পারবে · <?= h(date('j M · h:i A', strtotime((string) $branchReview['submitted_at']))) ?></span>
                </div>
                <a class="btn btn-secondary btn-sm" href="/teacher-traking/teacher/review.php?class_id=<?= $classId ?>" style="width:auto">View review</a>
              <?php else: ?>
                <div>
                  <strong>Branch review pending</strong>
                  <span>Cleanliness / facilities / staff — Admin ei details dekhbe</span>
                </div>
                <a class="btn btn-primary btn-sm" href="/teacher-traking/teacher/review.php?class_id=<?= $classId ?>" style="width:auto">Give branch review</a>
              <?php endif; ?>
            </div>

            <div class="dg-class-actions">
              <a class="btn btn-secondary btn-sm" href="<?= h($dashUrl) ?>" style="width:auto">Back to dashboard</a>
              <?php if ($onSite): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="action" value="branch_check_out" />
                  <button type="submit" class="btn btn-primary btn-sm" style="width:auto">Check out</button>
                </form>
              <?php elseif (!$everIn): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="action" value="branch_check_in" />
                  <button type="submit" class="btn btn-primary btn-sm" style="width:auto">Check in</button>
                </form>
              <?php else: ?>
                <span class="dg-saved">Already checked out</span>
              <?php endif; ?>
            </div>

          <?php elseif ($live): ?>
            <div class="dg-card-head"><h3>End this class</h3></div>
            <form method="post" class="dg-slot-form">
              <input type="hidden" name="action" value="teacher_end" />
              <input type="hidden" name="class_id" value="<?= $classId ?>" />
              <input type="hidden" name="teacher_class_end_at" value="<?= h(datetime_local_value(date('Y-m-d H:i:s'))) ?>" />
              <label>
                <span>Students present</span>
                <input type="number" name="student_count_teacher" min="0" placeholder="40" required />
              </label>
              <label>
                <span>End note (optional)</span>
                <textarea name="teacher_notes" rows="3" placeholder="Class summary…"></textarea>
              </label>
              <label>
                <span>Message to manager (optional · notification)</span>
                <textarea name="manager_message" rows="2" placeholder="Notify manager…"></textarea>
              </label>
              <div class="dg-class-actions">
                <button class="btn btn-primary" type="submit" style="width:auto">End class</button>
              </div>
            </form>
            <?php if ($onSite): ?>
              <form method="post" class="dg-checkout-inline">
                <input type="hidden" name="action" value="branch_check_out" />
                <button type="submit" class="btn btn-secondary btn-sm" style="width:auto">Check out of branch</button>
              </form>
            <?php endif; ?>

          <?php elseif ($blockedByPrev): ?>
            <div class="dg-card-head"><h3>Not yet</h3></div>
            <p class="dg-slot-hint" style="margin:0">Age <?= h((string) $blockSlot) ?> class ses korun — tarpor ei class start kora jabe.</p>
            <a class="btn btn-secondary btn-sm" href="<?= h($dashUrl) ?>" style="width:auto;margin-top:1rem">Back to dashboard</a>

          <?php else: ?>
            <div class="dg-card-head"><h3>Start this class</h3></div>
            <form method="post" class="dg-slot-form">
              <input type="hidden" name="action" value="teacher_start" />
              <input type="hidden" name="class_id" value="<?= $classId ?>" />
              <input type="hidden" name="teacher_class_start_at" value="<?= h(datetime_local_value(date('Y-m-d H:i:s'))) ?>" />
              <label>
                <span>Note (optional)</span>
                <textarea name="teacher_start_notes" rows="3" placeholder="Anything for the record…"></textarea>
              </label>
              <label>
                <span>Message to manager (optional · notification)</span>
                <textarea name="manager_message" rows="2" placeholder="Notify manager…"></textarea>
              </label>
              <button class="btn btn-primary" type="submit" style="width:auto">Start class</button>
            </form>
          <?php endif; ?>
        </section>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
