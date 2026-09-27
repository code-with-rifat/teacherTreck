<?php
/**
 * Teacher → Branch logistics review (Admin sees full details)
 * Supports create + re-edit with history snapshots.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/teacher.php';
$user = require_login(['teacher']);
ensure_review_counts_schema();

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

$classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$classStmt = $pdo->prepare(
    'SELECT c.*, b.is_outside_dhaka, b.name AS branch_name, b.city,
            cs.teacher_class_start_at, cs.teacher_class_end_at, cs.student_count_teacher,
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

$classUrl = '/teacher-traking/teacher/class.php?id=' . $classId;
$dashUrl = '/teacher-traking/teacher/dashboard.php?date=' . urlencode((string) $class['class_date']);
$reviewUrl = '/teacher-traking/teacher/review.php?class_id=' . $classId;
$editUrl = $reviewUrl . '&edit=1';

$existing = $pdo->prepare('SELECT * FROM class_reviews WHERE class_id = ? LIMIT 1');
$existing->execute([$classId]);
$review = $existing->fetch() ?: null;

if (empty($class['teacher_class_end_at']) && !$review) {
    flash('info', 'Finish the class first, then submit the branch review.');
    redirect($classUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {
    $isUpdate = (bool) $review;
    $failUrl = $isUpdate ? $editUrl : $reviewUrl;

    $outside = (int) $class['is_outside_dhaka'] === 1;
    $allowedClean = ['excellent', 'good', 'average', 'poor'];
    $allowedFac = ['working', 'issue', 'not_available'];
    $pick = static function (string $key, array $allowed, string $default): string {
        $v = (string) ($_POST[$key] ?? $default);
        return in_array($v, $allowed, true) ? $v : $default;
    };

    $data = [
        'cleanliness_staircase' => $pick('cleanliness_staircase', $allowedClean, 'good'),
        'cleanliness_classroom' => $pick('cleanliness_classroom', $allowedClean, 'good'),
        'cleanliness_teachers_room' => $pick('cleanliness_teachers_room', $allowedClean, 'good'),
        'facility_fan' => $pick('facility_fan', $allowedFac, 'working'),
        'facility_ac' => $pick('facility_ac', $allowedFac, 'working'),
        'facility_sound_system' => $pick('facility_sound_system', $allowedFac, 'working'),
        'facility_projector' => $pick('facility_projector', $allowedFac, 'working'),
        'staff_dress_code' => $pick('staff_dress_code', $allowedClean, 'good'),
        'staff_grooming' => $pick('staff_grooming', $allowedClean, 'good'),
        'staff_behavior' => $pick('staff_behavior', $allowedClean, 'good'),
    ];
    $reminder = !empty($_POST['reminder_call_received']) ? 1 : 0;
    $transport = $outside ? $pick('transport_rating', $allowedClean, 'good') : null;
    $transportComments = $outside ? (trim((string) ($_POST['transport_comments'] ?? '')) ?: null) : null;
    $extra = trim((string) ($_POST['additional_comments'] ?? '')) ?: null;

    $issue = in_array('poor', [
            $data['cleanliness_staircase'], $data['cleanliness_classroom'], $data['cleanliness_teachers_room'],
            $data['staff_dress_code'], $data['staff_grooming'], $data['staff_behavior'],
        ], true)
        || in_array($data['facility_fan'], ['issue', 'not_available'], true)
        || in_array($data['facility_ac'], ['issue', 'not_available'], true)
        || in_array($data['facility_sound_system'], ['issue', 'not_available'], true)
        || in_array($data['facility_projector'], ['issue', 'not_available'], true)
        || $transport === 'poor';

    $issueNotes = [];
    $noteFields = [
        'cleanliness_staircase' => 'poor',
        'cleanliness_classroom' => 'poor',
        'cleanliness_teachers_room' => 'poor',
        'facility_fan' => 'issue',
        'facility_ac' => 'issue',
        'facility_sound_system' => 'issue',
        'facility_projector' => 'issue',
        'staff_dress_code' => 'poor',
        'staff_grooming' => 'poor',
        'staff_behavior' => 'poor',
        'transport_rating' => 'poor',
    ];
    foreach ($noteFields as $field => $trigger) {
        $val = $data[$field] ?? ($field === 'transport_rating' ? $transport : null);
        if ($val === $trigger || ($trigger === 'issue' && in_array($val, ['issue', 'not_available'], true))) {
            $note = trim((string) ($_POST['issue_note_' . $field] ?? ''));
            if ($note === '') {
                $pretty = ucwords(str_replace('_', ' ', str_replace(['facility_', 'cleanliness_', 'staff_'], '', $field)));
                flash('error', 'Please describe the issue for: ' . $pretty);
                redirect($failUrl);
            }
            $issueNotes[$field] = $note;
        }
    }
    $issueNotesJson = $issueNotes ? json_encode($issueNotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $photos = [];
    if (!empty($_FILES['evidence_photos'])) {
        $photos = store_review_evidence_photos($classId, $_FILES['evidence_photos']);
    }
    if (!$photos && $isUpdate && $issue) {
        $existingPhotos = [];
        if (!empty($review['evidence_photos'])) {
            $decoded = json_decode((string) $review['evidence_photos'], true);
            if (is_array($decoded)) {
                $existingPhotos = $decoded;
            }
        }
        $photos = $existingPhotos;
    }
    if ($issue && !$photos) {
        flash('error', 'You marked a problem — please upload at least one photo.');
        redirect($failUrl);
    }
    $photosJson = $photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null;

    try {
        if ($isUpdate) {
            $editNo = (int) ($review['edit_count'] ?? 0) + 1;
            $snapshot = class_review_snapshot($review);
            $pdo->prepare(
                'INSERT INTO class_review_edits (class_review_id, class_id, edit_no, snapshot_json)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                (int) $review['id'],
                $classId,
                $editNo,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $pdo->prepare(
                'UPDATE class_reviews SET
                    cleanliness_staircase = ?, cleanliness_classroom = ?, cleanliness_teachers_room = ?,
                    facility_fan = ?, facility_ac = ?, facility_sound_system = ?, facility_projector = ?,
                    staff_dress_code = ?, staff_grooming = ?, staff_behavior = ?,
                    reminder_call_received = ?, transport_applicable = ?, transport_rating = ?, transport_comments = ?,
                    additional_comments = ?, evidence_photos = ?, issue_notes = ?, has_issue_flag = ?,
                    edit_count = edit_count + 1, last_edited_at = NOW()
                 WHERE id = ? AND class_id = ?'
            )->execute([
                $data['cleanliness_staircase'],
                $data['cleanliness_classroom'],
                $data['cleanliness_teachers_room'],
                $data['facility_fan'],
                $data['facility_ac'],
                $data['facility_sound_system'],
                $data['facility_projector'],
                $data['staff_dress_code'],
                $data['staff_grooming'],
                $data['staff_behavior'],
                $reminder,
                $outside ? 1 : 0,
                $transport,
                $transportComments,
                $extra,
                $photosJson,
                $issueNotesJson,
                $issue ? 1 : 0,
                (int) $review['id'],
                $classId,
            ]);
            flash('success', 'Branch review updated.');
        } else {
            $pdo->prepare(
                'INSERT INTO class_reviews (
                    class_id, teacher_id,
                    cleanliness_staircase, cleanliness_classroom, cleanliness_teachers_room,
                    facility_fan, facility_ac, facility_sound_system, facility_projector,
                    staff_dress_code, staff_grooming, staff_behavior,
                    reminder_call_received, transport_applicable, transport_rating, transport_comments,
                    additional_comments, evidence_photos, issue_notes, has_issue_flag
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $classId,
                $teacherId,
                $data['cleanliness_staircase'],
                $data['cleanliness_classroom'],
                $data['cleanliness_teachers_room'],
                $data['facility_fan'],
                $data['facility_ac'],
                $data['facility_sound_system'],
                $data['facility_projector'],
                $data['staff_dress_code'],
                $data['staff_grooming'],
                $data['staff_behavior'],
                $reminder,
                $outside ? 1 : 0,
                $transport,
                $transportComments,
                $extra,
                $photosJson,
                $issueNotesJson,
                $issue ? 1 : 0,
            ]);
            notify_teacher_class_action($pdo, $classId, 'review', $teacherName);
            flash('success', 'Branch review sent to Admin.');
        }
        redirect($reviewUrl);
    } catch (Throwable $e) {
        flash('error', 'Could not save review: ' . $e->getMessage());
        redirect($failUrl);
    }
}

// Refresh review row for display (POST paths redirect before this)
$existing->execute([$classId]);
$review = $existing->fetch() ?: null;

$showForm = !$review || (isset($_GET['edit']) && (string) $_GET['edit'] === '1');
$isEditForm = $showForm && (bool) $review;

$cleanOpts = ['excellent', 'good', 'average', 'poor'];
$facOpts = ['working', 'issue', 'not_available'];
$label = static function (string $v): string {
    return ucwords(str_replace('_', ' ', $v));
};

$evidenceList = [];
$issueNotesMap = [];
if ($review && !empty($review['evidence_photos'])) {
    $decoded = json_decode((string) $review['evidence_photos'], true);
    if (is_array($decoded)) {
        $evidenceList = $decoded;
    }
}
if ($review && !empty($review['issue_notes'])) {
    $decoded = json_decode((string) $review['issue_notes'], true);
    if (is_array($decoded)) {
        $issueNotesMap = $decoded;
    }
}

$sel = static function (?array $rev, string $field, string $fallback): string {
    return (string) ($rev[$field] ?? $fallback);
};

$metaBits = [];
if (!empty($class['subject'])) {
    $metaBits[] = $class['subject'];
}
$metaBits[] = ($class['course_category'] ?? '') === '2nd_timer' ? '2nd timer' : '1st timer';
if (!empty($class['lecture_no'])) {
    $metaBits[] = 'Lec ' . (int) $class['lecture_no'];
}

$nav = teacher_nav('dashboard', $teacherName);
extract($nav);
$pageTitle = 'Branch review';
$pageSub = $class['branch_name'] . ' · ' . format_time($class['time_slot']);
$hidePageHead = true;
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <div>
            <a class="dg-back" href="<?= h($classUrl) ?>">← Class</a>
            <h1 class="dg-hello" style="margin-top:.35rem">Branch review</h1>
            <p class="dg-class-sub">
              <?= h($class['branch_name']) ?> · <?= h(implode(' · ', $metaBits)) ?> ·
              <?= h(format_time($class['time_slot'])) ?> · <?= h(date('j M Y', strtotime((string) $class['class_date']))) ?>
            </p>
            <p class="dg-class-sub">Admin will see this review in full detail — cleanliness, facilities, staff, and photos.</p>
          </div>
          <?php if ($review): ?>
            <span class="dg-slot-badge is-ok"><?= (int) $review['has_issue_flag'] ? 'Flagged' : 'Submitted' ?></span>
            <?php if ((int) ($review['edit_count'] ?? 0) > 0): ?>
              <span class="dg-slot-badge is-accent">Edited <?= (int) $review['edit_count'] ?> times</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="dg-slot-badge is-accent">Pending</span>
          <?php endif; ?>
        </div>

        <?php if ($review && !$showForm): ?>
          <section class="dg-card dg-class-room dg-detail-card">
            <div class="dg-card-head"><h3>Submitted — visible to Admin</h3></div>
            <div class="dg-notes-panel">
              <div class="dg-note-box">
                <h4>Cleanliness</h4>
                <p>Staircase: <?= h($label($review['cleanliness_staircase'])) ?><?php if (!empty($issueNotesMap['cleanliness_staircase'])): ?> — <?= h($issueNotesMap['cleanliness_staircase']) ?><?php endif; ?><br>
                   Classroom: <?= h($label($review['cleanliness_classroom'])) ?><?php if (!empty($issueNotesMap['cleanliness_classroom'])): ?> — <?= h($issueNotesMap['cleanliness_classroom']) ?><?php endif; ?><br>
                   Teachers’ room: <?= h($label($review['cleanliness_teachers_room'])) ?><?php if (!empty($issueNotesMap['cleanliness_teachers_room'])): ?> — <?= h($issueNotesMap['cleanliness_teachers_room']) ?><?php endif; ?></p>
              </div>
              <div class="dg-note-box">
                <h4>Facilities</h4>
                <p>Fan: <?= h($label($review['facility_fan'])) ?><?php if (!empty($issueNotesMap['facility_fan'])): ?> — <?= h($issueNotesMap['facility_fan']) ?><?php endif; ?><br>
                   AC: <?= h($label($review['facility_ac'])) ?><?php if (!empty($issueNotesMap['facility_ac'])): ?> — <?= h($issueNotesMap['facility_ac']) ?><?php endif; ?><br>
                   Sound: <?= h($label($review['facility_sound_system'])) ?><?php if (!empty($issueNotesMap['facility_sound_system'])): ?> — <?= h($issueNotesMap['facility_sound_system']) ?><?php endif; ?><br>
                   Projector: <?= h($label($review['facility_projector'])) ?><?php if (!empty($issueNotesMap['facility_projector'])): ?> — <?= h($issueNotesMap['facility_projector']) ?><?php endif; ?></p>
              </div>
              <div class="dg-note-box">
                <h4>Staff</h4>
                <p>Dress: <?= h($label($review['staff_dress_code'])) ?><?php if (!empty($issueNotesMap['staff_dress_code'])): ?> — <?= h($issueNotesMap['staff_dress_code']) ?><?php endif; ?><br>
                   Grooming: <?= h($label($review['staff_grooming'])) ?><?php if (!empty($issueNotesMap['staff_grooming'])): ?> — <?= h($issueNotesMap['staff_grooming']) ?><?php endif; ?><br>
                   Behavior: <?= h($label($review['staff_behavior'])) ?><?php if (!empty($issueNotesMap['staff_behavior'])): ?> — <?= h($issueNotesMap['staff_behavior']) ?><?php endif; ?></p>
              </div>
              <div class="dg-note-box">
                <h4>Other</h4>
                <p>Reminder call: <?= (int) $review['reminder_call_received'] ? 'Yes' : 'No' ?>
                <?php if ((int) $review['transport_applicable']): ?>
                  <br>Transport: <?= h($label((string) $review['transport_rating'])) ?>
                  <?php if (!empty($issueNotesMap['transport_rating'])): ?> — <?= h($issueNotesMap['transport_rating']) ?><?php endif; ?>
                  <?php if (!empty($review['transport_comments'])): ?><br><?= h($review['transport_comments']) ?><?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($review['additional_comments'])): ?>
                  <br>Comments: <?= h($review['additional_comments']) ?>
                <?php endif; ?>
                </p>
              </div>
              <?php if ($evidenceList): ?>
                <div class="dg-note-box">
                  <h4>Issue photos</h4>
                  <div class="dg-evidence-grid">
                    <?php foreach ($evidenceList as $photo): ?>
                      <a href="/<?= h($photo) ?>" target="_blank" rel="noopener">
                        <img src="/<?= h($photo) ?>" alt="Evidence" />
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <div class="dg-class-actions">
              <a class="btn btn-primary btn-sm" href="<?= h($editUrl) ?>" style="width:auto">Edit review</a>
              <a class="btn btn-secondary btn-sm" href="<?= h($classUrl) ?>" style="width:auto">Back to class</a>
              <a class="btn btn-secondary btn-sm" href="<?= h($dashUrl) ?>" style="width:auto">Dashboard</a>
            </div>
          </section>
        <?php else: ?>
          <section class="dg-card dg-class-room">
            <div class="dg-card-head"><h3><?= $isEditForm ? 'Edit branch review' : 'Fill branch review for Admin' ?></h3></div>
            <form method="post" class="dg-review-form" enctype="multipart/form-data" id="branchReviewForm">
              <input type="hidden" name="action" value="submit_review" />
              <input type="hidden" name="class_id" value="<?= $classId ?>" />

              <h4 class="dg-review-sec">Cleanliness</h4>
              <div class="dg-review-grid">
                <?php
                $cleanFields = [
                    'cleanliness_staircase' => 'Staircase',
                    'cleanliness_classroom' => 'Classroom',
                    'cleanliness_teachers_room' => 'Teachers’ room',
                ];
                foreach ($cleanFields as $name => $title):
                    $cur = $sel($review, $name, 'good');
                ?>
                  <div class="dg-rate-field" data-trigger="poor">
                    <label><span><?= h($title) ?></span>
                      <select name="<?= h($name) ?>" class="js-rate"><?php foreach ($cleanOpts as $o): ?><option value="<?= h($o) ?>"<?= $o === $cur ? ' selected' : '' ?>><?= h($label($o)) ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="dg-issue-desc" hidden>
                      <span>Describe what was wrong</span>
                      <textarea name="issue_note_<?= h($name) ?>" rows="2" placeholder="e.g. dirty floor, smell…"><?= h($issueNotesMap[$name] ?? '') ?></textarea>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>

              <h4 class="dg-review-sec">Facilities</h4>
              <div class="dg-review-grid">
                <?php
                $facFields = [
                    'facility_fan' => 'Fan',
                    'facility_ac' => 'AC',
                    'facility_sound_system' => 'Sound system',
                    'facility_projector' => 'Projector',
                ];
                foreach ($facFields as $name => $title):
                    $cur = $sel($review, $name, 'working');
                ?>
                  <div class="dg-rate-field" data-trigger="issue">
                    <label><span><?= h($title) ?></span>
                      <select name="<?= h($name) ?>" class="js-rate"><?php foreach ($facOpts as $o): ?><option value="<?= h($o) ?>"<?= $o === $cur ? ' selected' : '' ?>><?= h($label($o)) ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="dg-issue-desc" hidden>
                      <span>Describe the issue</span>
                      <textarea name="issue_note_<?= h($name) ?>" rows="2" placeholder="e.g. not cooling, noise, broken…"><?= h($issueNotesMap[$name] ?? '') ?></textarea>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>

              <h4 class="dg-review-sec">Staff</h4>
              <div class="dg-review-grid">
                <?php
                $staffFields = [
                    'staff_dress_code' => 'Dress code',
                    'staff_grooming' => 'Grooming',
                    'staff_behavior' => 'Behavior',
                ];
                foreach ($staffFields as $name => $title):
                    $cur = $sel($review, $name, 'good');
                ?>
                  <div class="dg-rate-field" data-trigger="poor">
                    <label><span><?= h($title) ?></span>
                      <select name="<?= h($name) ?>" class="js-rate"><?php foreach ($cleanOpts as $o): ?><option value="<?= h($o) ?>"<?= $o === $cur ? ' selected' : '' ?>><?= h($label($o)) ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="dg-issue-desc" hidden>
                      <span>Describe what was wrong</span>
                      <textarea name="issue_note_<?= h($name) ?>" rows="2" placeholder="Briefly describe…"><?= h($issueNotesMap[$name] ?? '') ?></textarea>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="dg-reminder">
                <input type="checkbox" name="reminder_call_received" id="reminderCall" value="1"<?= $review && (int) $review['reminder_call_received'] ? ' checked' : '' ?> />
                <label for="reminderCall">Reminder call received before class</label>
              </div>

              <div class="dg-photo-box" id="evidenceBox" hidden>
                <h4 class="dg-review-sec" style="margin-top:0">Problem photos</h4>
                <p class="dg-slot-hint" style="margin:0 0 .5rem">You marked something as poor / issue — upload photos for Admin (required, max 4).<?= $isEditForm && $evidenceList ? ' Existing photos are kept if you do not upload new ones.' : '' ?></p>
                <?php if ($isEditForm && $evidenceList): ?>
                  <div class="dg-evidence-grid" style="margin-bottom:.75rem" data-existing-photos="1">
                    <?php foreach ($evidenceList as $photo): ?>
                      <a href="/<?= h($photo) ?>" target="_blank" rel="noopener">
                        <img src="/<?= h($photo) ?>" alt="Evidence" />
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <input type="file" name="evidence_photos[]" id="evidencePhotos" accept="image/jpeg,image/png,image/webp" multiple />
              </div>

              <?php if ((int) $class['is_outside_dhaka'] === 1): ?>
                <?php $transportCur = $sel($review, 'transport_rating', 'good'); ?>
                <h4 class="dg-review-sec">Transport (outside Dhaka)</h4>
                <div class="dg-review-grid">
                  <label><span>Transport rating</span>
                    <select name="transport_rating" class="js-rate"><?php foreach ($cleanOpts as $o): ?><option value="<?= h($o) ?>"<?= $o === $transportCur ? ' selected' : '' ?>><?= h($label($o)) ?></option><?php endforeach; ?></select>
                  </label>
                  <label class="dg-issue-desc" id="transportIssueDesc" hidden>
                    <span>Describe the transport issue</span>
                    <textarea name="issue_note_transport_rating" rows="2" placeholder="What went wrong…"><?= h($issueNotesMap['transport_rating'] ?? '') ?></textarea>
                  </label>
                </div>
                <label class="dg-field-block">
                  <span>Transport comments</span>
                  <textarea name="transport_comments" rows="2" placeholder="Optional…"><?= h($review['transport_comments'] ?? '') ?></textarea>
                </label>
              <?php endif; ?>

              <label class="dg-field-block">
                <span>Additional comments for Admin</span>
                <textarea name="additional_comments" rows="3" placeholder="Anything Admin should know…"><?= h($review['additional_comments'] ?? '') ?></textarea>
              </label>

              <div class="dg-class-actions">
                <button class="btn btn-primary" type="submit" style="width:auto"><?= $isEditForm ? 'Save changes' : 'Submit review to Admin' ?></button>
                <?php if ($isEditForm): ?>
                  <a class="btn btn-secondary btn-sm" href="<?= h($reviewUrl) ?>" style="width:auto">Cancel</a>
                <?php else: ?>
                  <a class="btn btn-secondary btn-sm" href="<?= h($classUrl) ?>" style="width:auto">Later</a>
                <?php endif; ?>
              </div>
            </form>
          </section>
          <script>
          (function () {
            const form = document.getElementById('branchReviewForm');
            const box = document.getElementById('evidenceBox');
            const file = document.getElementById('evidencePhotos');
            const hasExisting = !!document.querySelector('[data-existing-photos]');
            const badClean = new Set(['poor']);
            const badFac = new Set(['issue', 'not_available']);

            function fieldNeedsDesc(sel) {
              const wrap = sel.closest('.dg-rate-field');
              const trigger = wrap ? wrap.getAttribute('data-trigger') : '';
              if (trigger === 'issue') return badFac.has(sel.value);
              if (trigger === 'poor') return badClean.has(sel.value);
              if (sel.name === 'transport_rating') return badClean.has(sel.value);
              return false;
            }

            function syncField(sel) {
              const wrap = sel.closest('.dg-rate-field');
              const desc = wrap
                ? wrap.querySelector('.dg-issue-desc')
                : (sel.name === 'transport_rating' ? document.getElementById('transportIssueDesc') : null);
              if (!desc) return;
              const on = fieldNeedsDesc(sel);
              desc.hidden = !on;
              const ta = desc.querySelector('textarea');
              if (ta) {
                ta.required = on;
                if (!on) ta.value = '';
              }
            }

            function needsPhoto() {
              let bad = false;
              form.querySelectorAll('select.js-rate').forEach((sel) => {
                const v = sel.value;
                if (badClean.has(v) || badFac.has(v)) bad = true;
              });
              return bad;
            }

            function sync() {
              form.querySelectorAll('select.js-rate').forEach(syncField);
              const on = needsPhoto();
              box.hidden = !on;
              file.required = on && !hasExisting;
              if (!on) file.value = '';
            }

            form.querySelectorAll('select.js-rate').forEach((sel) => sel.addEventListener('change', sync));
            sync();
          })();
          </script>
        <?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
