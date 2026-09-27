<?php
/**
 * Branch Manager — Designo-style LMS dashboard
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';
ensure_review_counts_schema();
ensure_class_curriculum_schema();

$user = require_login(['branch_manager']);
$pdo = db();
$branch = manager_branch($pdo, $user);
manager_require_setup($branch);

$day = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
$isToday = $day === date('Y-m-d');

$metrics = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN class_date = ? THEN 1 ELSE 0 END) AS day_total,
        SUM(CASE WHEN class_date = ? AND status = \'completed\' THEN 1 ELSE 0 END) AS day_done,
        SUM(CASE WHEN class_date = ? AND status = \'in_progress\' THEN 1 ELSE 0 END) AS day_live,
        SUM(CASE WHEN class_date >= CURDATE() AND status = \'scheduled\' THEN 1 ELSE 0 END) AS upcoming
     FROM classes WHERE branch_id = ?'
);
$metrics->execute([$day, $day, $day, (int) $branch['id']]);
$m = $metrics->fetch() ?: [];
$dayTotalForPager = (int) ($m['day_total'] ?? 0);
$dashP = paginate_request(20);
$dashMeta = paginate_meta($dayTotalForPager, $dashP);

$dayClasses = $pdo->prepare(
    'SELECT c.id, c.class_date, c.time_slot, c.status, c.course_category, c.subject, c.lecture_no,
            c.teacher_id, t.full_name AS teacher_name, t.avatar_path,
            cs.check_in_at, cs.check_out_at,
            cs.teacher_class_start_at, cs.teacher_class_end_at,
            cs.manager_class_start_at, cs.manager_class_end_at, cs.manager_review_saved_at,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat
     FROM classes c
     JOIN teachers t ON t.id = c.teacher_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     WHERE c.branch_id = ? AND c.class_date = ?
     ORDER BY c.time_slot ASC, t.full_name ASC
     LIMIT ' . (int) $dashMeta['per'] . ' OFFSET ' . (int) $dashMeta['offset']
);
$dayClasses->execute([(int) $branch['id'], $day]);
$upcoming = $dayClasses->fetchAll();

$needAction = [];
$onSiteCount = 0;
$reviewedCount = 0;
foreach ($upcoming as $c) {
    $hasTimes = !empty($c['manager_class_start_at']) && !empty($c['manager_class_end_at']);
    $hasReview = $c['count_best'] !== null || $c['count_good'] !== null || !empty($c['manager_review_saved_at']);
    if (!$hasTimes || !$hasReview) {
        $needAction[] = $c;
    }
    if (!empty($c['check_in_at'])) {
        $onSiteCount++;
    }
    if ($hasReview) {
        $reviewedCount++;
    }
}
$dayTotal = (int) ($m['day_total'] ?? 0);
$dayDone = (int) ($m['day_done'] ?? 0);
$dayLive = (int) ($m['day_live'] ?? 0);
$pct = $dayTotal > 0 ? (int) round(($dayDone / $dayTotal) * 100) : 0;

/* Recompute site/reviewed from full day when list is paginated */
if ($dayTotal > count($upcoming)) {
    $sideSt = $pdo->prepare(
        'SELECT
           SUM(CASE WHEN cs.check_in_at IS NOT NULL THEN 1 ELSE 0 END) AS on_site,
           SUM(CASE WHEN cs.count_best IS NOT NULL OR cs.count_good IS NOT NULL OR cs.manager_review_saved_at IS NOT NULL THEN 1 ELSE 0 END) AS reviewed
         FROM classes c
         LEFT JOIN class_sessions cs ON cs.class_id = c.id
         WHERE c.branch_id = ? AND c.class_date = ?'
    );
    $sideSt->execute([(int) $branch['id'], $day]);
    $side = $sideSt->fetch() ?: [];
    $onSiteCount = (int) ($side['on_site'] ?? 0);
    $reviewedCount = (int) ($side['reviewed'] ?? 0);
}

$weekStart = date('Y-m-d', strtotime($day . ' -3 days'));
$weekDays = [];
$countStmt = $pdo->prepare(
    'SELECT class_date, COUNT(*) AS cnt,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS done
     FROM classes WHERE branch_id = ? AND class_date BETWEEN ? AND ?
     GROUP BY class_date'
);
$countStmt->execute([
    (int) $branch['id'],
    $weekStart,
    date('Y-m-d', strtotime($weekStart . ' +6 days')),
]);
$byDate = [];
$maxWeek = 1;
foreach ($countStmt->fetchAll() as $row) {
    $byDate[$row['class_date']] = $row;
    $maxWeek = max($maxWeek, (int) $row['cnt']);
}
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
    $cnt = (int) ($byDate[$d]['cnt'] ?? 0);
    $weekDays[] = [
        'date' => $d,
        'dow' => date('D', strtotime($d)),
        'dom' => date('j', strtotime($d)),
        'is_today' => $d === date('Y-m-d'),
        'is_active' => $d === $day,
        'count' => $cnt,
        'done' => (int) ($byDate[$d]['done'] ?? 0),
        'h' => max(10, (int) round(($cnt / $maxWeek) * 100)),
    ];
}

$dayTitle = $isToday ? 'today' : date('l', strtotime($day));
$dayFull = date('j M Y', strtotime($day));
$greetName = $branch['name'];
// Short greeting name from branch first word
$greetShort = trim(explode(' ', $greetName)[0] ?: 'Manager');

$nav = manager_nav('dashboard');
extract($nav);
$pageTitle = 'Dashboard';
$pageSub = $branch['name'];
$displayName = $branch['name'];
$hidePageHead = true;
$topActions = '';
require __DIR__ . '/../includes/app_header.php';
?>
        <h1 class="dg-hello">Hello <?= h($greetShort) ?> — let's run <?= h($dayTitle) ?>'s classes</h1>

        <div class="dg-toolbar">
          <?php if (!$isToday): ?>
            <a class="btn btn-secondary btn-sm" href="?date=<?= h(date('Y-m-d')) ?>">Today</a>
          <?php endif; ?>
          <a class="btn btn-secondary btn-sm" href="?date=<?= h($prevDay) ?>">←</a>
          <a class="btn btn-secondary btn-sm" href="?date=<?= h($nextDay) ?>">→</a>
          <form method="get" style="margin:0">
            <input type="date" name="date" value="<?= h($day) ?>" onchange="this.form.submit()"
                   style="border:1px solid var(--dg-line,#e5e7eb);border-radius:999px;padding:.4rem .75rem;background:#fff" />
          </form>
          <a class="btn btn-primary btn-sm" href="/teacherTreck/manager/classes.php?new=1&date=<?= h($day) ?>" style="width:auto;margin-left:auto">+ Assign class</a>
        </div>

        <div class="dg-grid">
          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Today's schedule progress</h3>
              <a class="dg-link" href="/teacherTreck/manager/classes.php">See all</a>
            </div>
            <div class="dg-progress-label"><?= h($branch['name']) ?> · <?= h($dayFull) ?></div>
            <div class="dg-progress-track">
              <div class="dg-progress-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="dg-progress-meta">
              <span><?= $dayDone ?> / <?= $dayTotal ?> completed</span>
              <span><?= $pct ?>%</span>
            </div>
            <div class="dg-progress-meta" style="margin-top:.65rem">
              <span>Live <?= $dayLive ?></span>
              <span>On site <?= $onSiteCount ?></span>
              <span>Reviewed <?= $reviewedCount ?></span>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Quick resources</h3>
            </div>
            <div class="dg-resource-list">
              <a class="dg-resource-item" href="/teacherTreck/manager/classes.php?new=1&date=<?= h($day) ?>">
                <span class="dg-file-ico">CL</span>
                <div>
                  <strong>Assign class</strong>
                  <span>Create a slot for a teacher</span>
                </div>
              </a>
              <a class="dg-resource-item" href="/teacherTreck/manager/teachers.php">
                <span class="dg-file-ico">TR</span>
                <div>
                  <strong>Teachers roster</strong>
                  <span>Day roster &amp; profiles</span>
                </div>
              </a>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Classes this week</h3>
            </div>
            <div class="dg-bars" aria-hidden="true">
              <?php foreach ($weekDays as $wd): ?>
                <a class="dg-bar-col<?= $wd['is_active'] ? ' is-active' : '' ?>" href="?date=<?= h($wd['date']) ?>" title="<?= (int) $wd['count'] ?> classes">
                  <div class="dg-bar" style="height:<?= (int) $wd['h'] ?>%"></div>
                  <span><?= h($wd['dow']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Performance</h3>
              <span class="muted" style="font-size:.75rem;font-weight:700">Day</span>
            </div>
            <div class="dg-gauge-wrap">
              <div class="dg-gauge" style="--p:<?= $pct ?>">
                <div>
                  <strong><?= number_format($pct / 10, 1) ?></strong>
                  <em>Done score</em>
                </div>
              </div>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>To-do · your entry</h3>
              <a class="dg-link" href="/teacherTreck/manager/classes.php">Open</a>
            </div>
            <?php if (!$needAction): ?>
              <div class="empty-state" style="padding:1rem 0"><strong>All caught up</strong>No pending times/reviews.</div>
            <?php else: ?>
              <ul class="dg-todo">
                <?php foreach (array_slice($needAction, 0, 5) as $c):
                    $hasTimes = !empty($c['manager_class_start_at']) && !empty($c['manager_class_end_at']);
                    $task = $hasTimes ? 'Add review' : (!empty($c['check_in_at']) ? 'Enter class times' : 'Awaiting check-in');
                ?>
                  <li>
                    <a href="/teacherTreck/manager/class.php?id=<?= (int) $c['id'] ?>">
                      <i class="dg-check" aria-hidden="true"></i>
                      <div>
                        <strong><?= h($c['teacher_name']) ?></strong>
                        <span><?= h(format_time($c['time_slot'])) ?> · <?= h($task) ?></span>
                      </div>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Calendar</h3>
            </div>
            <div class="dg-cal">
              <?php foreach ($weekDays as $wd): ?>
                <a class="<?= $wd['is_active'] ? 'is-active' : '' ?><?= $wd['is_today'] ? ' is-today' : '' ?>"
                   href="?date=<?= h($wd['date']) ?>">
                  <span class="dow"><?= h($wd['dow']) ?></span>
                  <span class="dom"><?= h((string) $wd['dom']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="dg-card dg-span-2">
            <div class="dg-card-head">
              <h3>Classes · <?= h($dayFull) ?></h3>
              <a class="dg-link" href="/teacherTreck/manager/classes.php">See more</a>
            </div>
            <?php if (!$upcoming): ?>
              <div class="empty-state"><strong>No classes this day</strong>Assign a class to get started.</div>
            <?php else: ?>
              <div class="dg-class-list">
                <?php foreach ($upcoming as $c):
                    $onSite = !empty($c['check_in_at']);
                    $hasTimes = !empty($c['manager_class_start_at']) && !empty($c['manager_class_end_at']);
                    $hasReview = $c['count_best'] !== null || $c['count_good'] !== null || !empty($c['manager_review_saved_at']);
                    $live = $onSite && !($hasTimes && $hasReview);
                ?>
                  <a class="dg-class-card<?= $live ? ' is-live' : '' ?>" href="/teacherTreck/manager/class.php?id=<?= (int) $c['id'] ?>">
                    <div class="dg-class-time"><?= h(format_time($c['time_slot'])) ?></div>
                    <div>
                      <strong><?= h($c['teacher_name']) ?></strong>
                      <div class="dg-class-meta">
                        <?php if (!empty($c['subject'])): ?>
                          <span class="dg-pill is-subject"><?= h($c['subject']) ?></span>
                        <?php endif; ?>
                        <span class="dg-pill"><?= ($c['course_category'] ?? '') === '2nd_timer' ? '2nd timer' : '1st timer' ?></span>
                        <?php if (!empty($c['lecture_no'])): ?>
                          <span class="dg-pill">L<?= (int) $c['lecture_no'] ?></span>
                        <?php endif; ?>
                        <span class="dg-pill"><?= h(ucfirst(str_replace('_', ' ', (string) $c['status']))) ?></span>
                      </div>
                    </div>
                    <span class="dg-link"><?= $hasReview ? 'View' : ($hasTimes ? 'Review' : 'Open') ?> →</span>
                  </a>
                <?php endforeach; ?>
              </div>
              <?= render_pager($dashMeta) ?>
            <?php endif; ?>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Upcoming slots</h3>
            </div>
            <div class="dg-upcoming">
              <?php
              $shown = 0;
              foreach ($upcoming as $c):
                  if (($c['status'] ?? '') === 'completed') {
                      continue;
                  }
                  if ($shown >= 6) {
                      break;
                  }
                  $shown++;
              ?>
                <a class="dg-up-item" href="/teacherTreck/manager/class.php?id=<?= (int) $c['id'] ?>">
                  <span><?= h($c['subject'] ?: $c['teacher_name']) ?></span>
                  <time><?= h(format_time($c['time_slot'])) ?></time>
                </a>
              <?php endforeach; ?>
              <?php if ($shown === 0): ?>
                <div class="muted" style="font-size:.85rem;padding:.35rem 0">No upcoming slots left today.</div>
              <?php endif; ?>
            </div>
          </section>
        </div>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
