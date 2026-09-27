<?php
/**
 * Class tracking — Manager start/end + review (clean UI)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';
ensure_review_counts_schema();
ensure_class_curriculum_schema();

$user = require_login(['branch_manager']);
$pdo = db();
$branch = manager_branch($pdo, $user);
manager_require_setup($branch);

$classId = (int) ($_GET['id'] ?? $_POST['class_id'] ?? 0);

$classStmt = $pdo->prepare(
    'SELECT c.*, t.full_name AS teacher_name, t.phone AS teacher_phone,
            cs.check_in_at, cs.check_out_at, cs.student_count_teacher, cs.student_count_manager,
            cs.signature_sheet_path, cs.teacher_notes, cs.verified_at,
            cs.teacher_class_start_at, cs.teacher_class_end_at, cs.teacher_times_saved_at,
            cs.manager_class_start_at, cs.manager_class_end_at, cs.manager_times_saved_at,
            cs.manager_review_saved_at,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat,
            r.id AS review_id, r.has_issue_flag, r.cleanliness_classroom, r.staff_behavior,
            r.submitted_at AS review_at
     FROM classes c
     JOIN teachers t ON t.id = c.teacher_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     WHERE c.id = ? AND c.branch_id = ?'
);
$classStmt->execute([$classId, (int) $branch['id']]);
$class = $classStmt->fetch();

if (!$class) {
    flash('error', 'Class not found in your branch.');
    redirect('/teacherTreck/manager/classes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'manager_times') {
        $startAt = parse_local_datetime($_POST['manager_class_start_at'] ?? '');
        $endAt = parse_local_datetime($_POST['manager_class_end_at'] ?? '');
        if (!$startAt || !$endAt) {
            flash('error', 'Class start and end time required.');
        } elseif (strtotime($endAt) < strtotime($startAt)) {
            flash('error', 'End time must be after start.');
        } else {
            $session = session_row_for_class($pdo, $classId);
            $pdo->prepare(
                'UPDATE class_sessions SET manager_class_start_at = ?, manager_class_end_at = ?,
                 manager_times_saved_at = NOW() WHERE id = ?'
            )->execute([$startAt, $endAt, (int) $session['id']]);
            notify_manager_class_action($pdo, $classId, 'times', (string) $branch['name']);
            flash('success', 'Start / end saved.');
        }
    }

    if ($action === 'manager_review') {
        $count = (int) ($_POST['student_count_manager'] ?? 0);
        $best = max(0, (int) ($_POST['count_best'] ?? 0));
        $good = max(0, (int) ($_POST['count_good'] ?? 0));
        $bad = max(0, (int) ($_POST['count_bad'] ?? 0));
        $repeat = max(0, (int) ($_POST['count_repeat'] ?? 0));
        $path = $class['signature_sheet_path'] ?? null;

        if (!empty($_FILES['signature_sheet']['tmp_name']) && is_uploaded_file($_FILES['signature_sheet']['tmp_name'])) {
            $dir = __DIR__ . '/../storage/uploads/signatures';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $ext = pathinfo($_FILES['signature_sheet']['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $name = 'sig_' . $classId . '_' . time() . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
            move_uploaded_file($_FILES['signature_sheet']['tmp_name'], $dir . '/' . $name);
            $path = 'storage/uploads/signatures/' . $name;
        }

        $session = session_row_for_class($pdo, $classId);
        $pdo->prepare(
            'UPDATE class_sessions SET student_count_manager = ?, count_best = ?, count_good = ?, count_bad = ?, count_repeat = ?,
             signature_sheet_path = COALESCE(?, signature_sheet_path),
             verified_by = ?, verified_at = NOW(), manager_review_saved_at = NOW()
             WHERE id = ?'
        )->execute([$count, $best, $good, $bad, $repeat, $path, (int) $user['id'], (int) $session['id']]);
        notify_manager_class_action($pdo, $classId, 'review', (string) $branch['name']);
        flash('success', 'Class review saved.');
    }

    redirect('/teacherTreck/manager/class.php?id=' . $classId);
}

$classStmt->execute([$classId, (int) $branch['id']]);
$class = $classStmt->fetch();

$mStart = $class['manager_class_start_at'] ?? null;
$mEnd = $class['manager_class_end_at'] ?? null;
$teacherPresent = !empty($class['check_in_at']);
$hasCounts = $class['count_best'] !== null || $class['count_good'] !== null
    || $class['count_bad'] !== null || $class['count_repeat'] !== null;
$reviewSummary = $hasCounts
    ? 'B' . (int) $class['count_best'] . ' · G' . (int) $class['count_good']
      . ' · Bad' . (int) $class['count_bad'] . ' · R' . (int) $class['count_repeat']
    : '—';

/* Same teacher · same day — each slot is its own class / review */
$sibSt = $pdo->prepare(
    'SELECT c.id, c.time_slot, c.subject, c.lecture_no, c.course_category,
            cs.manager_class_start_at, cs.manager_class_end_at, cs.manager_review_saved_at,
            cs.student_count_manager
     FROM classes c
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     WHERE c.branch_id = ? AND c.teacher_id = ? AND c.class_date = ? AND c.status <> \'cancelled\'
     ORDER BY c.time_slot ASC'
);
$sibSt->execute([(int) $branch['id'], (int) $class['teacher_id'], $class['class_date']]);
$siblings = $sibSt->fetchAll();

$slotDefaultStart = $class['class_date'] . ' ' . $class['time_slot'];
$slotDefaultEnd = date('Y-m-d H:i:s', strtotime($slotDefaultStart . ' +2 hours'));

$nav = manager_nav('classes');
extract($nav);
$pageTitle = 'Class tracking';
$pageSub = $class['teacher_name'] . ' · ' . format_time($class['time_slot']) . ' · ' . class_label($class);
$displayName = $branch['name'];
$topActions = '<a class="btn btn-secondary btn-sm" href="/teacherTreck/manager/classes.php" style="width:auto">← Classes</a>';
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="mgr-track">
          <?php if (count($siblings) > 1): ?>
            <div class="mgr-sib">
              <div class="mgr-sib-head">
                <strong>Same day · <?= count($siblings) ?> classes</strong>
                <span>প্রতিটা slot আলাদা — আলাদা start/end + student review দিতে হবে</span>
              </div>
              <div class="mgr-sib-list">
                <?php foreach ($siblings as $s):
                    $isHere = (int) $s['id'] === $classId;
                    $sDone = !empty($s['manager_review_saved_at']);
                    $sTimes = !empty($s['manager_class_start_at']) && !empty($s['manager_class_end_at']);
                    $sLabel = $sDone ? 'Reviewed' : ($sTimes ? 'Times saved' : 'Pending');
                    $sClass = $sDone ? 'is-done' : ($sTimes ? 'is-mid' : 'is-open');
                ?>
                  <a class="mgr-sib-item<?= $isHere ? ' is-here' : '' ?> <?= h($sClass) ?>"
                     href="/teacherTreck/manager/class.php?id=<?= (int) $s['id'] ?>">
                    <em><?= h(format_time($s['time_slot'])) ?></em>
                    <strong><?= h($s['subject'] ?: 'Class') ?><?php if (!empty($s['lecture_no'])): ?> · L<?= (int) $s['lecture_no'] ?><?php endif; ?></strong>
                    <span><?= h($sLabel) ?><?php if ($s['student_count_manager'] !== null): ?> · <?= (int) $s['student_count_manager'] ?> stu<?php endif; ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="mgr-track-stats">
            <div>
              <span>Class</span>
              <strong><?= h(class_label($class)) ?></strong>
              <em><?= h(format_time($class['time_slot'])) ?> · <?= h(date('j M Y', strtotime((string) $class['class_date']))) ?></em>
            </div>
            <div>
              <span>Teacher</span>
              <strong><?= $teacherPresent ? 'On site' : 'Not in yet' ?></strong>
              <em><?= $teacherPresent ? 'Checked in ' . h(format_clock($class['check_in_at'])) : 'Waiting for check-in' ?></em>
            </div>
            <div>
              <span>Your times</span>
              <strong><?= h(format_clock($mStart)) ?> → <?= h(format_clock($mEnd)) ?></strong>
              <em><?= $class['manager_times_saved_at'] ? 'Saved' : 'Not saved yet' ?></em>
            </div>
            <div>
              <span>Review</span>
              <strong><?= h($reviewSummary) ?></strong>
              <em><?= $class['manager_review_saved_at'] ? 'Saved' : 'Pending' ?></em>
            </div>
          </div>

          <div class="mgr-track-grid">
            <section class="panel mgr-track-card">
              <div class="panel-header"><h3>Teacher</h3></div>
              <div class="panel-body">
                <p class="mgr-track-name"><?= h($class['teacher_name']) ?></p>
                <p class="mgr-track-meta"><?= h($class['teacher_phone']) ?></p>
                <a class="btn btn-secondary btn-sm" href="/teacherTreck/manager/teacher.php?id=<?= (int) $class['teacher_id'] ?>" style="width:auto;margin-top:.75rem">Open profile</a>
              </div>
            </section>

            <section class="panel mgr-track-card">
              <div class="panel-header"><h3>Your start / end</h3></div>
              <div class="panel-body">
                <form method="post" class="mgr-track-form">
                  <input type="hidden" name="action" value="manager_times" />
                  <input type="hidden" name="class_id" value="<?= $classId ?>" />
                  <div class="form-group">
                    <label>Start</label>
                    <input type="datetime-local" name="manager_class_start_at" required
                           value="<?= h(datetime_local_value($mStart ?: $slotDefaultStart)) ?>" />
                  </div>
                  <div class="form-group">
                    <label>End</label>
                    <input type="datetime-local" name="manager_class_end_at" required
                           value="<?= h(datetime_local_value($mEnd ?: $slotDefaultEnd)) ?>" />
                  </div>
                  <button class="btn btn-primary" type="submit" style="width:auto">Save times</button>
                </form>
              </div>
            </section>
          </div>

          <section class="panel mgr-track-card">
            <div class="panel-header">
              <h3>Class review · <?= h(format_time($class['time_slot'])) ?></h3>
            </div>
            <div class="panel-body">
              <p class="mgr-track-meta" style="margin:0 0 .85rem">
                শুধু এই slot-এর students — অন্য schedule-এর সাথে মিশবে না।
              </p>
              <form method="post" enctype="multipart/form-data" class="mgr-track-form">
                <input type="hidden" name="action" value="manager_review" />
                <input type="hidden" name="class_id" value="<?= $classId ?>" />
                <div class="form-group">
                  <label>Students present (this class only)</label>
                  <input type="number" name="student_count_manager" min="0" required
                         value="<?= h((string) ($class['student_count_manager'] ?? '')) ?>"
                         placeholder="e.g. 40" />
                </div>
                <div class="review-counts">
                  <div class="form-group">
                    <label>Best</label>
                    <input type="number" name="count_best" min="0" value="<?= (int) ($class['count_best'] ?? 0) ?>" required />
                  </div>
                  <div class="form-group">
                    <label>Good</label>
                    <input type="number" name="count_good" min="0" value="<?= (int) ($class['count_good'] ?? 0) ?>" required />
                  </div>
                  <div class="form-group">
                    <label>Bad</label>
                    <input type="number" name="count_bad" min="0" value="<?= (int) ($class['count_bad'] ?? 0) ?>" required />
                  </div>
                  <div class="form-group">
                    <label>Repeat</label>
                    <input type="number" name="count_repeat" min="0" value="<?= (int) ($class['count_repeat'] ?? 0) ?>" required />
                  </div>
                </div>
                <div class="form-group">
                  <label>Signature sheet (optional)</label>
                  <input type="file" name="signature_sheet" accept="image/*,application/pdf" />
                  <?php if (!empty($class['signature_sheet_path'])): ?>
                    <p class="mgr-track-meta" style="margin-top:.4rem">
                      Current file: <a href="/teacherTreck/<?= h($class['signature_sheet_path']) ?>" target="_blank" rel="noopener">View</a>
                    </p>
                  <?php endif; ?>
                </div>
                <button class="btn btn-primary" type="submit" style="width:auto">Save review</button>
              </form>
              <?php if ($class['review_id']): ?>
                <p class="mgr-track-meta" style="margin-top:1rem">
                  Teacher branch review:
                  <?= (int) $class['has_issue_flag'] ? '<span class="badge badge-issue">Flagged</span>' : '<span class="badge badge-ok">OK</span>' ?>
                  <?php if (!empty($class['cleanliness_classroom'])): ?>
                    · Classroom <?= h((string) $class['cleanliness_classroom']) ?>
                  <?php endif; ?>
                </p>
              <?php endif; ?>
            </div>
          </section>
        </div>
<style>
.mgr-track { display: grid; gap: 1.15rem; }
.mgr-sib {
  background: #fff;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 16px;
  padding: 0.85rem 1rem 1rem;
}
.mgr-sib-head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.35rem 0.75rem;
  margin-bottom: 0.7rem;
}
.mgr-sib-head strong {
  font-size: 0.92rem;
  font-weight: 750;
}
.mgr-sib-head span {
  font-size: 0.8rem;
  color: var(--slate-500, #64748b);
}
.mgr-sib-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 0.5rem;
}
.mgr-sib-item {
  display: grid;
  gap: 0.15rem;
  padding: 0.7rem 0.75rem;
  border-radius: 12px;
  border: 1px solid #e8eaee;
  text-decoration: none;
  color: inherit;
  background: #fafbfc;
}
.mgr-sib-item:hover { border-color: #ffd5c4; background: #fffaf8; }
.mgr-sib-item.is-here {
  border-color: #ffb89a;
  background: #fff4ef;
  box-shadow: inset 0 0 0 1px #ffb89a;
}
.mgr-sib-item em {
  font-style: normal;
  font-size: 0.95rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.mgr-sib-item strong {
  font-size: 0.78rem;
  font-weight: 650;
  color: var(--slate-700, #334155);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mgr-sib-item span {
  font-size: 0.72rem;
  font-weight: 650;
  color: var(--slate-500, #64748b);
}
.mgr-sib-item.is-done span { color: #166534; }
.mgr-sib-item.is-mid span { color: #9a3412; }
.mgr-track-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.75rem;
}
.mgr-track-stats > div {
  background: #fff;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 16px;
  padding: 0.95rem 1rem;
  box-shadow: 0 1px 2px rgba(28,29,33,.04);
}
.mgr-track-stats span {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--slate-500, #64748b);
  margin-bottom: 0.35rem;
}
.mgr-track-stats strong {
  display: block;
  font-size: 1rem;
  font-weight: 750;
  color: var(--navy-900, #111827);
  line-height: 1.3;
}
.mgr-track-stats em {
  display: block;
  margin-top: 0.3rem;
  font-style: normal;
  font-size: 0.8rem;
  color: var(--slate-500, #64748b);
}
.mgr-track-grid {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 1rem;
}
.mgr-track-card .panel-body { padding-top: 0.35rem; }
.mgr-track-name {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 750;
  color: var(--navy-900, #111827);
}
.mgr-track-meta {
  margin: 0.3rem 0 0;
  font-size: 0.88rem;
  color: var(--slate-500, #64748b);
}
.mgr-track-form .form-group { margin-bottom: 0.85rem; }
.mgr-track-form label {
  font-size: 0.82rem;
  font-weight: 650;
  color: var(--slate-600, #475569);
}
@media (max-width: 900px) {
  .mgr-track-stats { grid-template-columns: 1fr 1fr; }
  .mgr-track-grid { grid-template-columns: 1fr; }
}
</style>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
