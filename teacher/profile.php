<?php
/**
 * Teacher profile — Designo layout
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/teacher.php';
$user = require_login(['teacher']);
ensure_review_counts_schema();
ensure_class_curriculum_schema();
$pdo = db();

$teacherStmt = $pdo->prepare('SELECT * FROM teachers WHERE user_id = ?');
$teacherStmt->execute([(int) $user['id']]);
$teacher = $teacherStmt->fetch();
if (!$teacher) {
    flash('error', 'Teacher profile missing.');
    redirect('/teacher-traking/logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $row->execute([(int) $user['id']]);
    $u = $row->fetch();
    if (!$u || !password_verify($current, $u['password_hash'])) {
        flash('error', 'Current password wrong.');
    } elseif (strlen($new) < 8) {
        flash('error', 'New password must be 8+ characters.');
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), (int) $user['id']]);
        flash('success', 'Password updated.');
    }
    redirect('/teacher-traking/teacher/profile.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_photo') {
    try {
        $path = save_teacher_avatar($_FILES['avatar'] ?? [], (int) $teacher['id']);
        if (!$path) {
            flash('error', 'Photo select করুন (JPG/PNG/WebP, max 3MB).');
        } else {
            $pdo->prepare('UPDATE teachers SET avatar_path = ? WHERE id = ?')
                ->execute([$path, (int) $teacher['id']]);
            flash('success', 'Profile photo updated. Manager এখন দেখতে পারবে।');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/teacher-traking/teacher/profile.php');
}

$stats = $pdo->prepare(
    'SELECT
        COUNT(DISTINCT c.id) AS total_classes,
        SUM(CASE WHEN cs.check_in_at IS NOT NULL THEN 1 ELSE 0 END) AS checkins,
        SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS branch_reviews
     FROM classes c
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     WHERE c.teacher_id = ?'
);
$stats->execute([(int) $teacher['id']]);
$st = $stats->fetch() ?: [];

$history = $pdo->prepare(
    'SELECT c.id AS class_id, c.class_date, c.time_slot, c.status, c.subject, c.lecture_no, c.course_category,
            b.name AS branch_name,
            cs.check_in_at, cs.check_out_at, cs.student_count_teacher,
            cs.teacher_class_start_at, cs.teacher_class_end_at,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat,
            r.id AS review_id, r.has_issue_flag
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     WHERE c.teacher_id = ?
     ORDER BY c.class_date DESC, c.time_slot DESC
     LIMIT 30'
);
$history->execute([(int) $teacher['id']]);
$rows = $history->fetchAll();

$photo = teacher_photo_src($teacher['avatar_path'] ?? null);
$initials = teacher_initials($teacher['full_name']);
$dob = (string) ($teacher['date_of_birth'] ?? '');
$dobLabel = $dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)
    ? date('j M Y', strtotime($dob))
    : ($dob !== '' ? $dob : '—');

$pageTitle = 'My profile';
$pageSub = $teacher['full_name'];
$hidePageHead = true;
$nav = teacher_nav('profile', (string) $teacher['full_name']);
extract($nav);
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <div>
            <h1 class="dg-hello">My profile</h1>
            <p class="admin-page-sub" style="margin:.15rem 0 0"><?= h($teacher['full_name']) ?></p>
          </div>
        </div>

        <div class="report-strip report-strip-dash tp-stats">
          <div class="report-item">
            <span class="report-label">Classes</span>
            <span class="report-value"><?= (int) ($st['total_classes'] ?? 0) ?></span>
            <span class="report-hint">Assigned</span>
          </div>
          <div class="report-item">
            <span class="report-label">Check-ins</span>
            <span class="report-value"><?= (int) ($st['checkins'] ?? 0) ?></span>
            <span class="report-hint">Attendance</span>
          </div>
          <div class="report-item">
            <span class="report-label">Reviews</span>
            <span class="report-value"><?= (int) ($st['branch_reviews'] ?? 0) ?></span>
            <span class="report-hint">Branch reviews</span>
          </div>
        </div>

        <div class="tp-layout">
          <section class="dg-card tp-card">
            <div class="tp-hero">
              <div class="teacher-photo tp-avatar">
                <?php if ($photo): ?>
                  <img src="<?= h($photo) ?>" alt="<?= h($teacher['full_name']) ?>" />
                <?php else: ?>
                  <span><?= h($initials) ?></span>
                <?php endif; ?>
              </div>
              <div class="tp-hero-text">
                <strong><?= h($teacher['full_name']) ?></strong>
                <span><?= h($user['email']) ?></span>
              </div>
            </div>

            <div class="tp-fields">
              <div>
                <em>Phone</em>
                <strong><?= h($teacher['phone'] ?: '—') ?></strong>
              </div>
              <div>
                <em>Emergency</em>
                <strong><?= h($teacher['emergency_contact'] ?: '—') ?></strong>
              </div>
              <div>
                <em>Date of birth</em>
                <strong><?= h($dobLabel) ?></strong>
              </div>
              <div>
                <em>College</em>
                <strong><?= h($teacher['medical_college'] ?: '—') ?></strong>
              </div>
              <div class="tp-span">
                <em>Address</em>
                <strong><?= h($teacher['address'] ?: '—') ?></strong>
              </div>
            </div>

            <form method="post" enctype="multipart/form-data" class="tp-upload">
              <input type="hidden" name="action" value="upload_photo" />
              <label>Profile photo <span>(Manager দেখবে)</span></label>
              <div class="tp-upload-row">
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required />
                <button class="btn btn-secondary btn-sm" type="submit" style="width:auto">Upload</button>
              </div>
            </form>
          </section>

          <section class="dg-card tp-card">
            <div class="dg-card-head"><h3>Password</h3></div>
            <form method="post" class="br-form">
              <input type="hidden" name="action" value="change_password" />
              <div class="form-group">
                <label>Current password</label>
                <input type="password" name="current_password" required autocomplete="current-password" />
              </div>
              <div class="form-group">
                <label>New password</label>
                <input type="password" name="new_password" minlength="8" required autocomplete="new-password" />
              </div>
              <button class="btn btn-primary btn-sm" type="submit" style="width:auto">Update password</button>
            </form>
            <p class="muted" style="font-size:.8rem;margin:1rem 0 0">
              Forgot current password?
              <a href="/teacher-traking/forgot-password.php">Email recovery</a>
            </p>
          </section>
        </div>

        <section class="dg-card" style="margin-top:1rem">
          <div class="dg-card-head">
            <h3>Class history</h3>
            <span class="muted" style="font-size:.78rem;font-weight:650"><?= count($rows) ?></span>
          </div>

          <?php if (!$rows): ?>
            <div class="empty-state"><strong>No classes yet</strong></div>
          <?php else: ?>
            <div class="tp-history">
              <?php foreach ($rows as $r):
                  $cid = (int) $r['class_id'];
                  $isLive = !empty($r['teacher_class_start_at']) && empty($r['teacher_class_end_at']);
                  $ended = !empty($r['teacher_class_end_at']);
                  $hasReview = !empty($r['review_id']);
                  $stLabel = $isLive || ($r['status'] ?? '') === 'in_progress'
                      ? 'Live'
                      : ucfirst(str_replace('_', ' ', (string) ($r['status'] ?? 'scheduled')));
              ?>
                <a class="tp-hist-item" href="/teacher-traking/teacher/class.php?id=<?= $cid ?>">
                  <div class="tp-hist-when">
                    <strong><?= h(format_time($r['time_slot'])) ?></strong>
                    <span><?= h(date('j M Y', strtotime((string) $r['class_date']))) ?></span>
                  </div>
                  <div class="tp-hist-main">
                    <strong><?= h(class_label($r)) ?></strong>
                    <span><?= h($r['branch_name']) ?></span>
                    <?php if ($r['count_best'] !== null || $r['count_good'] !== null): ?>
                      <em>B<?= (int) $r['count_best'] ?> · G<?= (int) $r['count_good'] ?> · Bad<?= (int) $r['count_bad'] ?> · R<?= (int) $r['count_repeat'] ?></em>
                    <?php endif; ?>
                  </div>
                  <div class="tp-hist-side">
                    <span class="br-pill<?= $isLive ? '' : ' is-ok' ?>"><?= h($stLabel) ?></span>
                    <?php if ($ended && !$hasReview): ?>
                      <span class="tp-hist-link">Review →</span>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
