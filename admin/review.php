<?php
/**
 * Admin — all classes for a teacher on that day:
 * class notes + manager review + branch review together
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';
$user = require_login(['admin', 'super_admin']);
ensure_review_counts_schema();
ensure_class_curriculum_schema();
$pdo = db();

$classId = (int) ($_GET['class_id'] ?? 0);
$st = $pdo->prepare(
    'SELECT c.id AS class_id, c.teacher_id, c.branch_id, c.class_date, c.time_slot,
            c.course_category, c.subject, c.lecture_no, c.status,
            b.name AS branch_name, b.city,
            t.full_name AS teacher_name, t.phone AS teacher_phone
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     JOIN teachers t ON t.id = c.teacher_id
     WHERE c.id = ?'
);
$st->execute([$classId]);
$focus = $st->fetch();
if (!$focus) {
    flash('error', 'Class not found.');
    redirect('/admin/reviews.php');
}

$teacherId = (int) $focus['teacher_id'];
$day = (string) $focus['class_date'];

$list = $pdo->prepare(
    'SELECT c.id AS class_id, c.class_date, c.time_slot, c.course_category, c.subject, c.lecture_no, c.status,
            b.name AS branch_name, b.city,
            cs.check_in_at, cs.student_count_teacher, cs.student_count_manager,
            cs.teacher_class_start_at, cs.teacher_class_end_at,
            cs.manager_class_start_at, cs.manager_class_end_at,
            cs.manager_review_saved_at,
            cs.teacher_notes, cs.teacher_start_notes,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat,
            r.id AS review_id, r.has_issue_flag, r.submitted_at, r.edit_count,
            r.cleanliness_staircase, r.cleanliness_classroom, r.cleanliness_teachers_room,
            r.facility_fan, r.facility_ac, r.facility_sound_system, r.facility_projector,
            r.staff_dress_code, r.staff_grooming, r.staff_behavior,
            r.reminder_call_received, r.transport_applicable, r.transport_rating, r.transport_comments,
            r.additional_comments, r.evidence_photos, r.issue_notes
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     WHERE c.teacher_id = ? AND c.class_date = ?
     ORDER BY c.time_slot ASC, c.id ASC'
);
$list->execute([$teacherId, $day]);
$dayClasses = $list->fetchAll();

$lab = static function (?string $v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    return ucwords(str_replace('_', ' ', $v));
};
$noteLabel = static function (string $k): string {
    return ucwords(str_replace('_', ' ', preg_replace('/^(facility_|cleanliness_|staff_)/', '', $k) ?? $k));
};

$nav = admin_nav('reviews');
extract($nav);
$pageTitle = 'Teacher day reviews';
$pageSub = $focus['teacher_name'] . ' · ' . $day;
$displayName = $user['email'];
$hidePageHead = true;
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <h1 class="dg-hello"><?= h($focus['teacher_name']) ?></h1>
          <div class="dg-toolbar">
            <a class="btn btn-secondary btn-sm" href="/admin/reviews.php">← Reviews</a>
          </div>
        </div>
        <p class="admin-page-sub">
          <?= h(date('l, j M Y', strtotime($day))) ?>
          · <?= count($dayClasses) ?> class<?= count($dayClasses) === 1 ? '' : 'es' ?>
          <?php if (!empty($focus['teacher_phone'])): ?>
            · <?= h($focus['teacher_phone']) ?>
          <?php endif; ?>
        </p>

        <div class="admin-day-stack">
          <?php foreach ($dayClasses as $i => $row):
              $cid = (int) $row['class_id'];
              $isFocus = $cid === $classId;
              $tStart = $row['teacher_class_start_at'] ?? null;
              $tEnd = $row['teacher_class_end_at'] ?? null;
              $mStart = $row['manager_class_start_at'] ?? null;
              $mEnd = $row['manager_class_end_at'] ?? null;
              $hasMgr = $row['count_best'] !== null || $row['count_good'] !== null
                  || $row['count_bad'] !== null || $row['count_repeat'] !== null
                  || !empty($row['manager_review_saved_at']);
              $hasBranch = !empty($row['review_id']);
              $issueNotes = [];
              if (!empty($row['issue_notes'])) {
                  $jn = json_decode((string) $row['issue_notes'], true);
                  if (is_array($jn)) {
                      $issueNotes = $jn;
                  }
              }
              $photos = [];
              if (!empty($row['evidence_photos'])) {
                  $d = json_decode((string) $row['evidence_photos'], true);
                  if (is_array($d)) {
                      $photos = $d;
                  }
              }
          ?>
            <section class="admin-day-class<?= $isFocus ? ' is-focus' : '' ?>" id="class-<?= $cid ?>">
              <header class="admin-day-class-head">
                <div>
                  <em>#<?= $i + 1 ?></em>
                  <strong><?= h(format_time($row['time_slot'])) ?></strong>
                  <span><?= h(class_label($row)) ?></span>
                </div>
                <div class="admin-day-class-tags">
                  <span class="admin-mod-tag"><?= h($row['branch_name']) ?></span>
                  <?php if ($hasBranch && (int) $row['has_issue_flag']): ?>
                    <span class="admin-slot-status is-flag">Flagged</span>
                  <?php elseif ($hasBranch): ?>
                    <span class="admin-slot-status is-done">Branch OK</span>
                  <?php endif; ?>
                  <?php if ($hasMgr): ?>
                    <span class="admin-slot-status is-live">Mgr review</span>
                  <?php endif; ?>
                </div>
              </header>

              <div class="admin-day-grid">
                <div class="admin-compare-block">
                  <h3>Times</h3>
                  <dl class="admin-dl">
                    <div><dt>Teacher</dt><dd><?= $tStart || $tEnd ? h(format_clock($tStart)) . ' → ' . h(format_clock($tEnd)) : '—' ?></dd></div>
                    <div><dt>Manager</dt><dd><?= $mStart || $mEnd ? h(format_clock($mStart)) . ' → ' . h(format_clock($mEnd)) : '—' ?></dd></div>
                    <div>
                      <dt>Students</dt>
                      <dd>
                        T <?= $row['student_count_teacher'] !== null ? (int) $row['student_count_teacher'] : '—' ?>
                        · M <?= $row['student_count_manager'] !== null ? (int) $row['student_count_manager'] : '—' ?>
                      </dd>
                    </div>
                  </dl>
                </div>

                <div class="admin-compare-block">
                  <h3>Class notes (teacher)</h3>
                  <p><strong>Start:</strong> <?= h(trim((string) ($row['teacher_start_notes'] ?? '')) ?: '—') ?></p>
                  <p><strong>End:</strong> <?= h(trim((string) ($row['teacher_notes'] ?? '')) ?: '—') ?></p>
                </div>

                <div class="admin-compare-block">
                  <h3>Manager class review</h3>
                  <?php if (!$hasMgr): ?>
                    <p class="admin-compare-empty">No manager counts yet.</p>
                  <?php else: ?>
                    <div class="admin-count-grid">
                      <div><span>Best</span><strong><?= (int) ($row['count_best'] ?? 0) ?></strong></div>
                      <div><span>Good</span><strong><?= (int) ($row['count_good'] ?? 0) ?></strong></div>
                      <div><span>Bad</span><strong><?= (int) ($row['count_bad'] ?? 0) ?></strong></div>
                      <div><span>Repeat</span><strong><?= (int) ($row['count_repeat'] ?? 0) ?></strong></div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="admin-compare-block admin-day-branch">
                  <h3>Branch review (teacher) · with photos</h3>
                  <?php if (!$hasBranch): ?>
                    <p class="admin-compare-empty">No branch review for this class.</p>
                  <?php else:
                      $ratingFields = [
                          'cleanliness_staircase' => 'Stair',
                          'cleanliness_classroom' => 'Classroom',
                          'cleanliness_teachers_room' => 'Teachers’ room',
                          'facility_fan' => 'Fan',
                          'facility_ac' => 'AC',
                          'facility_sound_system' => 'Sound',
                          'facility_projector' => 'Projector',
                          'staff_dress_code' => 'Dress',
                          'staff_grooming' => 'Grooming',
                          'staff_behavior' => 'Behavior',
                      ];
                      $problems = [];
                      foreach ($ratingFields as $fk => $flabel) {
                          $val = (string) ($row[$fk] ?? '');
                          if (in_array($val, ['poor', 'issue', 'not_available'], true)) {
                              $problems[$fk] = [
                                  'label' => $flabel,
                                  'value' => $lab($val),
                                  'note' => (string) ($issueNotes[$fk] ?? ''),
                              ];
                          }
                      }
                      if ((int) ($row['transport_applicable'] ?? 0) && ($row['transport_rating'] ?? '') === 'poor') {
                          $problems['transport_rating'] = [
                              'label' => 'Transport',
                              'value' => 'Poor',
                              'note' => trim((string) ($row['transport_comments'] ?? '') . ' ' . (string) ($issueNotes['transport_rating'] ?? '')),
                          ];
                      }
                      $hasProblem = (int) ($row['has_issue_flag'] ?? 0) === 1 || $problems || $photos;
                  ?>
                    <p class="admin-compare-when">
                      <?= h(date('j M · g:i A', strtotime((string) $row['submitted_at']))) ?>
                      <?php if ((int) ($row['edit_count'] ?? 0) > 0): ?>
                        · Edited <?= (int) $row['edit_count'] ?>×
                      <?php endif; ?>
                    </p>

                    <?php if ($problems || (int) ($row['has_issue_flag'] ?? 0) === 1): ?>
                      <div class="admin-problem-banner is-bad">
                        <strong>Problem reported</strong>
                        <span><?= count($problems) ?> item<?= count($problems) === 1 ? '' : 's' ?> need attention<?= $photos ? ' · photos attached' : '' ?></span>
                      </div>
                    <?php else: ?>
                      <div class="admin-problem-banner is-ok">
                        <strong>No problem flagged</strong>
                        <span>Branch ratings look fine for this class</span>
                      </div>
                    <?php endif; ?>

                    <?php if ($problems): ?>
                      <div class="admin-problem-list">
                        <?php foreach ($problems as $prob): ?>
                          <div class="admin-problem-item">
                            <div>
                              <strong><?= h($prob['label']) ?></strong>
                              <em><?= h($prob['value']) ?></em>
                            </div>
                            <?php if (trim($prob['note']) !== ''): ?>
                              <p><?= h($prob['note']) ?></p>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                    <?php if ($photos): ?>
                      <div class="admin-photo-panel">
                        <strong>Evidence photos · tap to open</strong>
                        <div class="admin-photo-grid">
                          <?php foreach ($photos as $pi => $p): ?>
                            <a href="/<?= h($p) ?>" target="_blank" rel="noopener" title="Photo <?= $pi + 1 ?>">
                              <img src="/<?= h($p) ?>" alt="Evidence <?= $pi + 1 ?>" loading="lazy" />
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php elseif ($problems): ?>
                      <p class="admin-compare-empty">Problem marked but no photo uploaded.</p>
                    <?php endif; ?>

                    <details class="admin-rating-details">
                      <summary>All branch ratings</summary>
                      <dl class="admin-dl">
                        <?php foreach ($ratingFields as $fk => $flabel):
                            $val = (string) ($row[$fk] ?? '');
                            $bad = in_array($val, ['poor', 'issue', 'not_available'], true);
                        ?>
                          <div>
                            <dt><?= h($flabel) ?></dt>
                            <dd class="<?= $bad ? 'is-warn' : '' ?>"><?= h($lab($val)) ?></dd>
                          </div>
                        <?php endforeach; ?>
                        <div><dt>Reminder</dt><dd><?= (int) $row['reminder_call_received'] ? 'Yes' : 'No' ?></dd></div>
                        <div>
                          <dt>Transport</dt>
                          <dd class="<?= (($row['transport_rating'] ?? '') === 'poor') ? 'is-warn' : '' ?>">
                            <?php if ((int) $row['transport_applicable']): ?>
                              <?= h($lab($row['transport_rating'] ?? null)) ?>
                            <?php else: ?>
                              N/A
                            <?php endif; ?>
                          </dd>
                        </div>
                      </dl>
                    </details>

                    <?php if (!empty($row['additional_comments'])): ?>
                      <div class="admin-compare-notes">
                        <strong>Comments</strong>
                        <p><?= nl2br(h((string) $row['additional_comments'])) ?></p>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
        <script>
        (function () {
          var el = document.getElementById('class-<?= (int) $classId ?>');
          if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })();
        </script>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
