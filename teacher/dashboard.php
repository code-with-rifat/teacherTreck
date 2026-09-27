<?php
/**
 * Teacher dashboard — assigned slots → multi check-in/out → time-wise start/end + reports
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/teacher.php';
$user = require_login(['teacher']);
ensure_review_counts_schema();
ensure_class_curriculum_schema();
ensure_teacher_flow_schema();

$pdo = db();
$teacherStmt = $pdo->prepare('SELECT * FROM teachers WHERE user_id = ?');
$teacherStmt->execute([(int) $user['id']]);
$teacher = $teacherStmt->fetch();
if (!$teacher) {
    flash('error', 'Teacher profile missing.');
    redirect('/logout.php');
}
$teacherId = (int) $teacher['id'];
$teacherName = (string) $teacher['full_name'];

$day = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
$isToday = $day === date('Y-m-d');

$list = $pdo->prepare(
    'SELECT c.*, b.name AS branch_name, b.city, b.latitude, b.longitude,
            COALESCE(b.geofence_radius_m, (
              SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = \'global_checkin_radius_m\'
            )) AS checkin_radius_m,
            cs.check_in_at, cs.check_out_at, cs.student_count_teacher, cs.student_count_manager,
            cs.teacher_class_start_at, cs.teacher_class_end_at, cs.teacher_times_saved_at,
            cs.teacher_notes, cs.teacher_start_notes,
            cs.manager_class_start_at, cs.manager_class_end_at, cs.manager_review_saved_at,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     WHERE c.teacher_id = ? AND c.class_date = ?
     ORDER BY c.time_slot ASC, c.id ASC'
);
$list->execute([$teacherId, $day]);
$classes = $list->fetchAll();

$branchId = $classes ? (int) $classes[0]['branch_id'] : 0;
$branchMeta = null;
if ($branchId > 0) {
    $bSt = $pdo->prepare(
        'SELECT id, name, latitude, longitude,
                COALESCE(geofence_radius_m, (
                  SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = \'global_checkin_radius_m\'
                )) AS checkin_radius_m
         FROM branches WHERE id = ?'
    );
    $bSt->execute([$branchId]);
    $branchMeta = $bSt->fetch() ?: null;
}
$visit = $branchId > 0 ? teacher_branch_day($pdo, $teacherId, $branchId, $day) : [];
$onSite = teacher_visit_on_site($visit);
$everIn = !empty($visit['check_in_at']);

$dashUrl = '/teacher/dashboard.php?date=' . urlencode($day);

// ---- Check-in (first stamp kept; re-entry updates last_check_in_at only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'branch_check_in') {
    if ($branchId < 1 || !$branchMeta) {
        flash('error', 'No class/branch today.');
        redirect($dashUrl);
    }
    if ($onSite) {
        flash('info', 'Already checked in. Check out first if you leave.');
        redirect($dashUrl);
    }

    if (!$everIn) {
        $pdo->prepare(
            'UPDATE teacher_branch_days
             SET check_in_at = NOW(), last_check_in_at = NOW()
             WHERE teacher_id = ? AND branch_id = ? AND visit_date = ?'
        )->execute([$teacherId, $branchId, $day]);
    } else {
        $pdo->prepare(
            'UPDATE teacher_branch_days SET last_check_in_at = NOW()
             WHERE teacher_id = ? AND branch_id = ? AND visit_date = ?'
        )->execute([$teacherId, $branchId, $day]);
    }

    foreach ($classes as $c) {
        if ((int) $c['branch_id'] !== $branchId) {
            continue;
        }
        $session = session_row_for_class($pdo, (int) $c['id']);
        if (empty($session['check_in_at'])) {
            $pdo->prepare('UPDATE class_sessions SET check_in_at = NOW() WHERE id = ?')
                ->execute([(int) $session['id']]);
        }
    }

    notify_branch_visit($pdo, $branchId, $teacherName, 'check_in', $day);
    flash('success', $everIn ? 'Checked in again.' : 'First check-in saved. Start your first slot.');
    redirect($dashUrl);
}

// ---- Check-out (only if currently checked in) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'branch_check_out') {
    if (!$onSite) {
        flash('error', 'Check in first — check out without check-in possible na.');
        redirect($dashUrl);
    }

    $pdo->prepare(
        'UPDATE teacher_branch_days SET check_out_at = NOW()
         WHERE teacher_id = ? AND branch_id = ? AND visit_date = ?'
    )->execute([$teacherId, $branchId, $day]);

    foreach ($classes as $c) {
        if ((int) $c['branch_id'] !== $branchId) {
            continue;
        }
        $session = session_row_for_class($pdo, (int) $c['id']);
        if (!empty($session['teacher_class_end_at'])) {
            $pdo->prepare('UPDATE class_sessions SET check_out_at = NOW() WHERE id = ?')
                ->execute([(int) $session['id']]);
        }
    }

    notify_branch_visit($pdo, $branchId, $teacherName, 'check_out', $day);
    flash('success', 'Checked out. Lagbe hole abar check in korun.');
    redirect($dashUrl);
}

$list->execute([$teacherId, $day]);
$classes = $list->fetchAll();
$visit = $branchId > 0 ? teacher_branch_day($pdo, $teacherId, $branchId, $day) : [];
$onSite = teacher_visit_on_site($visit);
$everIn = !empty($visit['check_in_at']);

$stats = $pdo->prepare('SELECT * FROM v_teacher_stats WHERE teacher_id = ?');
$stats->execute([$teacherId]);
$st = $stats->fetch() ?: ['total_completed' => 0, 'total_checkins' => 0, 'rating_best' => 0];

$dayDone = 0;
$openEnds = 0;
$dayReviewed = 0;
$sumBest = 0;
$sumGood = 0;
$sumBad = 0;
$sumRepeat = 0;
$sumStudents = 0;
$todos = [];
foreach ($classes as $c) {
    $tEnd = $c['teacher_class_end_at'] ?? null;
    $tStart = $c['teacher_class_start_at'] ?? null;
    $hasReview = $c['count_best'] !== null || $c['count_good'] !== null
        || !empty($c['manager_review_saved_at']);
    if ($tEnd) {
        $dayDone++;
    } else {
        $openEnds++;
        $todos[] = [
            'c' => $c,
            'task' => $tStart ? 'Open class · End' : 'Open class · Start',
        ];
    }
    if ($hasReview) {
        $dayReviewed++;
        $sumBest += (int) ($c['count_best'] ?? 0);
        $sumGood += (int) ($c['count_good'] ?? 0);
        $sumBad += (int) ($c['count_bad'] ?? 0);
        $sumRepeat += (int) ($c['count_repeat'] ?? 0);
        if ($c['student_count_manager'] !== null) {
            $sumStudents += (int) $c['student_count_manager'];
        }
    }
}
if ($classes) {
    array_unshift($todos, [
        'c' => null,
        'task' => $onSite ? 'On branch · check out when leaving' : ($everIn ? 'Check in again (branch presence)' : 'Check in (branch presence)'),
    ]);
}
$dayTotal = count($classes);
$pct = $dayTotal > 0 ? (int) round(($dayDone / $dayTotal) * 100) : 0;
$dayPendingReview = max(0, $dayTotal - $dayReviewed);

$nextClassId = 0;
foreach ($classes as $c) {
    if (empty($c['teacher_class_end_at'])) {
        $nextClassId = (int) $c['id'];
        break;
    }
}

$weekStart = date('Y-m-d', strtotime($day . ' -3 days'));
$weekDays = [];
$countStmt = $pdo->prepare(
    'SELECT class_date, COUNT(*) AS cnt FROM classes WHERE teacher_id = ? AND class_date BETWEEN ? AND ? GROUP BY class_date'
);
$countStmt->execute([$teacherId, $weekStart, date('Y-m-d', strtotime($weekStart . ' +6 days'))]);
$byDate = [];
$maxWeek = 1;
foreach ($countStmt->fetchAll() as $row) {
    $byDate[$row['class_date']] = (int) $row['cnt'];
    $maxWeek = max($maxWeek, (int) $row['cnt']);
}
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
    $cnt = (int) ($byDate[$d] ?? 0);
    $weekDays[] = [
        'date' => $d,
        'dow' => date('D', strtotime($d)),
        'dom' => date('j', strtotime($d)),
        'is_today' => $d === date('Y-m-d'),
        'is_active' => $d === $day,
        'count' => $cnt,
        'h' => max(10, (int) round(($cnt / $maxWeek) * 100)),
    ];
}

$dayFull = date('j M Y', strtotime($day));
$greetShort = trim(explode(' ', $teacherName)[0] ?: 'Teacher');
$allCompleted = (int) ($st['total_completed'] ?? 0);
$gaugePct = min(100, $pct ?: ($allCompleted > 0 ? 40 : 0));
$firstIn = $visit['check_in_at'] ?? null;
$lastIn = $visit['last_check_in_at'] ?? $firstIn;
$lastOut = $visit['check_out_at'] ?? null;

$nav = teacher_nav('dashboard', $teacherName);
extract($nav);
$pageTitle = 'Dashboard';
$pageSub = $dayFull;
$hidePageHead = true;
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <h1 class="dg-hello">Hello <?= h($greetShort) ?></h1>
          <div class="dg-toolbar">
            <?php if (!$isToday): ?>
              <a class="btn btn-secondary btn-sm" href="?date=<?= h(date('Y-m-d')) ?>">Today</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="?date=<?= h($prevDay) ?>">←</a>
            <a class="btn btn-secondary btn-sm" href="?date=<?= h($nextDay) ?>">→</a>
            <form method="get" style="margin:0">
              <input type="date" name="date" value="<?= h($day) ?>" onchange="this.form.submit()" />
            </form>
          </div>
        </div>

        <?php if ($classes && $branchMeta): ?>
          <div class="dg-checkbar<?= $onSite ? ' is-in' : ($everIn ? ' is-out' : '') ?>" id="branchVisit">
            <div class="dg-checkbar-text">
              <strong><?= h($branchMeta['name']) ?></strong>
              <span>
                <?php if (!$everIn): ?>
                  Branch presence · check in when you arrive (class start er jonno dorkar nai)
                <?php elseif ($onSite): ?>
                  On site · first in <?= h(format_clock($firstIn)) ?>
                  <?php if ($lastIn && $lastIn !== $firstIn): ?> · back <?= h(format_clock($lastIn)) ?><?php endif; ?>
                <?php else: ?>
                  Out <?= h(format_clock($lastOut)) ?> · first in was <?= h(format_clock($firstIn)) ?>
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
        <?php endif; ?>

        <div class="report-strip report-strip-dash teach-day-report">
          <div class="report-item">
            <span class="report-label">Classes</span>
            <span class="report-value"><?= (int) $dayTotal ?></span>
            <span class="report-hint"><?= h($dayFull) ?></span>
          </div>
          <div class="report-item">
            <span class="report-label">Finished</span>
            <span class="report-value"><?= (int) $dayDone ?></span>
            <span class="report-hint"><?= (int) $openEnds ?> open</span>
          </div>
          <div class="report-item">
            <span class="report-label">Reviewed</span>
            <span class="report-value"><?= (int) $dayReviewed ?></span>
            <span class="report-hint"><?= (int) $dayPendingReview ?> pending</span>
          </div>
          <div class="report-item">
            <span class="report-label">Students</span>
            <span class="report-value"><?= (int) $sumStudents ?></span>
            <span class="report-hint">Manager count</span>
          </div>
        </div>

        <div class="dg-grid">
          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Today's class progress</h3>
              <span class="muted" style="font-size:.75rem;font-weight:700"><?= h($dayFull) ?></span>
            </div>
            <div class="dg-progress-label">Click a class · start / end inside</div>
            <div class="dg-progress-track">
              <div class="dg-progress-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="dg-progress-meta">
              <span><?= (int) $dayDone ?> / <?= (int) $dayTotal ?> finished</span>
              <span><?= (int) $pct ?>%</span>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Day review report</h3></div>
            <?php if ($dayTotal < 1): ?>
              <div class="empty-state"><strong>No classes</strong>Assign hole summary ekhane asbe.</div>
            <?php elseif ($dayReviewed < 1): ?>
              <div class="empty-state"><strong>Review pending</strong>Manager save korle quality mix dekhabe.</div>
            <?php else: ?>
              <div class="teach-mix">
                <div><em>Best</em><strong><?= (int) $sumBest ?></strong></div>
                <div><em>Good</em><strong><?= (int) $sumGood ?></strong></div>
                <div><em>Bad</em><strong><?= (int) $sumBad ?></strong></div>
                <div><em>Repeat</em><strong><?= (int) $sumRepeat ?></strong></div>
              </div>
              <p class="muted" style="font-size:.8rem;margin:.75rem 0 0">
                <?= (int) $dayReviewed ?> / <?= (int) $dayTotal ?> class reviewed
                · <?= (int) $sumStudents ?> students counted
              </p>
            <?php endif; ?>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Quick resources</h3></div>
            <div class="dg-resource-list">
              <a class="dg-resource-item" href="/teacher/profile.php">
                <span class="dg-file-ico">ME</span>
                <div><strong>My profile</strong><span>Photo &amp; details</span></div>
              </a>
              <a class="dg-resource-item" href="/notifications.php">
                <span class="dg-file-ico">ACT</span>
                <div><strong>Activity</strong><span>Your updates</span></div>
              </a>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Classes this week</h3></div>
            <div class="dg-bars">
              <?php foreach ($weekDays as $wd): ?>
                <a class="dg-bar-col<?= $wd['is_active'] ? ' is-active' : '' ?>" href="?date=<?= h($wd['date']) ?>">
                  <div class="dg-bar" style="height:<?= (int) $wd['h'] ?>%"></div>
                  <span><?= h($wd['dow']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Performance</h3></div>
            <div class="dg-gauge-wrap">
              <div class="dg-gauge" style="--p:<?= (int) $gaugePct ?>">
                <div><strong><?= number_format($gaugePct / 10, 1) ?></strong><em>Day score</em></div>
              </div>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>To-do · next steps</h3></div>
            <?php if (!$classes): ?>
              <div class="empty-state"><strong>No classes</strong>Nothing scheduled today.</div>
            <?php elseif (!$todos): ?>
              <div class="empty-state"><strong>All caught up</strong></div>
            <?php else: ?>
              <ul class="dg-todo">
                <?php foreach (array_slice($todos, 0, 6) as $item): ?>
                  <li>
                    <a href="<?= $item['c'] ? '/teacher/class.php?id=' . (int) $item['c']['id'] : '#branchVisit' ?>">
                      <i class="dg-check"></i>
                      <div>
                        <strong><?= h($item['c']['subject'] ?? 'Branch') ?></strong>
                        <span><?= h($item['task']) ?></span>
                      </div>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Calendar</h3></div>
            <div class="dg-cal">
              <?php foreach ($weekDays as $wd): ?>
                <a class="<?= $wd['is_active'] ? 'is-active' : '' ?><?= $wd['is_today'] ? ' is-today' : '' ?>" href="?date=<?= h($wd['date']) ?>">
                  <span class="dow"><?= h($wd['dow']) ?></span>
                  <span class="dom"><?= h((string) $wd['dom']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="dg-card dg-span-2 dg-day-classes" id="dayClasses">
            <div class="dg-card-head">
              <h3>Assigned classes · <?= h($dayFull) ?></h3>
              <span class="muted" style="font-size:.75rem;font-weight:700"><?= (int) $dayTotal ?> slot<?= $dayTotal === 1 ? '' : 's' ?></span>
            </div>

            <?php if (!$classes): ?>
              <div class="empty-state"><strong>No class assigned</strong>Manager assign korle ekhane asbe + notification.</div>
            <?php else: ?>
              <div class="dg-slot-list">
                <?php
                $ord = 0;
                foreach ($classes as $c):
                    $ord++;
                    $cid = (int) $c['id'];
                    $tStart = $c['teacher_class_start_at'] ?? null;
                    $tEnd = $c['teacher_class_end_at'] ?? null;
                    $done = !empty($tEnd);
                    $live = !empty($tStart) && !$done;
                    $isNext = $cid === $nextClassId;

                    $hasReview = $c['count_best'] !== null || $c['count_good'] !== null
                        || !empty($c['manager_review_saved_at']);
                    $metaBits = [];
                    if (!empty($c['subject'])) {
                        $metaBits[] = $c['subject'];
                    }
                    $metaBits[] = ($c['course_category'] ?? '') === '2nd_timer' ? '2nd timer' : '1st timer';
                    if (!empty($c['lecture_no'])) {
                        $metaBits[] = 'Lec ' . (int) $c['lecture_no'];
                    }
                ?>
                  <a class="dg-slot dg-slot-link<?= $live ? ' is-live' : '' ?><?= $done ? ' is-done' : '' ?><?= $isNext && !$done ? ' is-next' : '' ?>"
                     href="/teacher/class.php?id=<?= $cid ?>" id="class-<?= $cid ?>">
                    <header class="dg-slot-head">
                      <div class="dg-slot-time">
                        <em>#<?= $ord ?></em>
                        <strong><?= h(format_time($c['time_slot'])) ?></strong>
                      </div>
                      <div class="dg-slot-meta">
                        <strong><?= h($c['subject'] ?: $c['branch_name']) ?></strong>
                        <span><?= h(implode(' · ', $metaBits)) ?> · <?= h($c['branch_name']) ?></span>
                        <?php if ($hasReview): ?>
                          <span class="teach-slot-review">
                            Review B<?= (int) ($c['count_best'] ?? 0) ?>
                            · G<?= (int) ($c['count_good'] ?? 0) ?>
                            · Bad<?= (int) ($c['count_bad'] ?? 0) ?>
                            · R<?= (int) ($c['count_repeat'] ?? 0) ?>
                            <?php if ($c['student_count_manager'] !== null): ?>
                              · <?= (int) $c['student_count_manager'] ?> stu
                            <?php endif; ?>
                          </span>
                        <?php elseif ($done): ?>
                          <span><?= h(format_clock($tStart)) ?> → <?= h(format_clock($tEnd)) ?> · review pending</span>
                        <?php elseif ($live): ?>
                          <span>Live · tap to end / note</span>
                        <?php elseif ($isNext): ?>
                          <span>Next · tap to open &amp; start</span>
                        <?php else: ?>
                          <span>Tap to open</span>
                        <?php endif; ?>
                      </div>
                      <span class="dg-slot-badge">
                        <?php if ($hasReview): ?>Reviewed
                        <?php elseif ($done): ?>Done
                        <?php elseif ($live): ?>Live
                        <?php elseif ($isNext): ?>Next
                        <?php else: ?>Open
                        <?php endif; ?>
                      </span>
                    </header>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Upcoming slots</h3></div>
            <div class="dg-upcoming">
              <?php
              $shown = 0;
              foreach ($classes as $c):
                  if (!empty($c['teacher_class_end_at'])) {
                      continue;
                  }
                  if ($shown >= 6) {
                      break;
                  }
                  $shown++;
              ?>
                <a class="dg-up-item" href="/teacher/class.php?id=<?= (int) $c['id'] ?>">
                  <span><?= h($c['subject'] ?: $c['branch_name']) ?></span>
                  <time><?= h(format_time($c['time_slot'])) ?></time>
                </a>
              <?php endforeach; ?>
              <?php if ($shown === 0): ?>
                <div class="muted" style="font-size:.85rem;padding:.35rem 0">No open slots left.</div>
              <?php endif; ?>
            </div>
          </section>
        </div>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
