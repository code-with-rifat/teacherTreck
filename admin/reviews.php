<?php
/**
 * Admin — Class reviews (paginated list)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';
$user = require_login(['admin', 'super_admin']);
ensure_review_counts_schema();
ensure_class_curriculum_schema();
$pdo = db();

$flaggedOnly = isset($_GET['flagged']);
$compareOnly = isset($_GET['compare']);

$where = 'WHERE (cs.check_in_at IS NOT NULL OR cs.teacher_class_start_at IS NOT NULL
               OR cs.manager_class_start_at IS NOT NULL OR r.id IS NOT NULL)';
if ($flaggedOnly) {
    $where .= ' AND r.has_issue_flag = 1';
}
if ($compareOnly) {
    $where .= ' AND cs.teacher_class_start_at IS NOT NULL AND cs.manager_class_start_at IS NOT NULL';
}

$from = 'FROM classes c
        JOIN branches b ON b.id = c.branch_id
        JOIN teachers t ON t.id = c.teacher_id
        LEFT JOIN class_sessions cs ON cs.class_id = c.id
        LEFT JOIN class_reviews r ON r.class_id = c.id
        ' . $where;

$total = (int) $pdo->query('SELECT COUNT(*) ' . $from)->fetchColumn();
$p = paginate_request(20);
$meta = paginate_meta($total, $p);

$sql = 'SELECT c.id AS class_id, c.class_date, c.time_slot, c.course_category, c.subject, c.lecture_no, c.status,
               b.name AS branch_name,
               t.full_name AS teacher_name,
               cs.check_in_at, cs.student_count_teacher, cs.student_count_manager,
               cs.teacher_class_start_at, cs.teacher_class_end_at,
               cs.manager_class_start_at, cs.manager_class_end_at,
               cs.manager_review_saved_at,
               cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat,
               r.id AS review_id, r.has_issue_flag
        ' . $from . '
        ORDER BY COALESCE(cs.manager_review_saved_at, cs.manager_times_saved_at, cs.teacher_times_saved_at, c.class_date) DESC
        LIMIT ' . (int) $meta['per'] . ' OFFSET ' . (int) $meta['offset'];
$rows = $pdo->query($sql)->fetchAll();

$filterLabel = $flaggedOnly ? 'Flagged' : ($compareOnly ? 'Both entered' : 'All sessions');

$nav = admin_nav('reviews');
extract($nav);
$pageTitle = 'Reviews';
$pageSub = 'Teacher vs manager';
$displayName = $user['email'];
$hidePageHead = true;
$topActions = '';
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <h1 class="dg-hello">Reviews</h1>
          <div class="dg-toolbar admin-filter-bar">
            <a class="admin-filter<?= !$flaggedOnly && !$compareOnly ? ' is-on' : '' ?>" href="/teacher-traking/admin/reviews.php">All</a>
            <a class="admin-filter<?= $compareOnly ? ' is-on' : '' ?>" href="/teacher-traking/admin/reviews.php?compare=1">Both entered</a>
            <a class="admin-filter<?= $flaggedOnly ? ' is-on' : '' ?>" href="/teacher-traking/admin/reviews.php?flagged=1">Flagged</a>
          </div>
        </div>
        <p class="admin-page-sub"><?= h($filterLabel) ?> · <?= (int) $total ?> record<?= $total === 1 ? '' : 's' ?></p>

        <?php if (!$rows): ?>
          <section class="dg-card is-fit">
            <div class="empty-state"><strong>No data yet</strong>Sessions appear after teacher or manager entry.</div>
          </section>
        <?php else: ?>
          <div class="admin-review-list">
            <?php foreach ($rows as $r):
                $tStart = $r['teacher_class_start_at'] ?? null;
                $tEnd = $r['teacher_class_end_at'] ?? null;
                $mStart = $r['manager_class_start_at'] ?? null;
                $mEnd = $r['manager_class_end_at'] ?? null;
                $startDiff = ($tStart && $mStart) ? abs(strtotime((string) $tStart) - strtotime((string) $mStart)) : null;
                $endDiff = ($tEnd && $mEnd) ? abs(strtotime((string) $tEnd) - strtotime((string) $mEnd)) : null;
                $diffWarn = ($startDiff !== null && $startDiff > 900) || ($endDiff !== null && $endDiff > 900);
                $hasCounts = $r['count_best'] !== null || $r['count_good'] !== null
                    || $r['count_bad'] !== null || $r['count_repeat'] !== null
                    || !empty($r['manager_review_saved_at']);
                $flagged = !empty($r['has_issue_flag']);
            ?>
              <a class="admin-review-card<?= $flagged ? ' is-flag' : '' ?>" href="/teacher-traking/admin/review.php?class_id=<?= (int) $r['class_id'] ?>">
                <div class="admin-review-main">
                  <strong><?= h($r['teacher_name']) ?></strong>
                  <span class="admin-review-meta">
                    <?= h($r['branch_name']) ?>
                    · <?= h(class_label($r)) ?>
                  </span>
                  <span class="admin-review-when">
                    <?= h(date('j M Y', strtotime((string) $r['class_date']))) ?>
                    · <?= h(format_time($r['time_slot'])) ?>
                  </span>
                </div>

                <div class="admin-review-cols">
                  <div>
                    <em>Teacher</em>
                    <span><?= $tStart || $tEnd ? h(format_clock($tStart)) . ' → ' . h(format_clock($tEnd)) : '—' ?></span>
                    <?php if (!empty($r['check_in_at'])): ?>
                      <small>In <?= h(format_clock($r['check_in_at'])) ?></small>
                    <?php endif; ?>
                  </div>
                  <div>
                    <em>Manager</em>
                    <span><?= $mStart || $mEnd ? h(format_clock($mStart)) . ' → ' . h(format_clock($mEnd)) : '—' ?></span>
                    <?php if ($startDiff !== null || $endDiff !== null): ?>
                      <small class="<?= $diffWarn ? 'is-warn' : 'is-ok' ?>">
                        Δ <?= $startDiff !== null ? round($startDiff / 60) . 'm' : '—' ?>
                        / <?= $endDiff !== null ? round($endDiff / 60) . 'm' : '—' ?>
                      </small>
                    <?php endif; ?>
                  </div>
                  <div>
                    <em>Review</em>
                    <span>
                      <?php if ($hasCounts): ?>
                        B<?= (int) ($r['count_best'] ?? 0) ?>
                        · G<?= (int) ($r['count_good'] ?? 0) ?>
                        · Bad<?= (int) ($r['count_bad'] ?? 0) ?>
                        · R<?= (int) ($r['count_repeat'] ?? 0) ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </span>
                    <small>
                      T <?= $r['student_count_teacher'] !== null ? (int) $r['student_count_teacher'] : '—' ?>
                      · M <?= $r['student_count_manager'] !== null ? (int) $r['student_count_manager'] : '—' ?>
                    </small>
                  </div>
                </div>

                <div class="admin-review-side">
                  <?php if ($flagged): ?>
                    <span class="admin-slot-status is-flag">Flagged</span>
                  <?php elseif ($r['review_id']): ?>
                    <span class="admin-slot-status is-done">OK</span>
                  <?php elseif ($hasCounts): ?>
                    <span class="admin-slot-status is-live">Counts</span>
                  <?php else: ?>
                    <span class="admin-slot-status is-open">Open</span>
                  <?php endif; ?>
                  <span class="admin-review-go">Details →</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
          <?= render_pager($meta) ?>
        <?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
