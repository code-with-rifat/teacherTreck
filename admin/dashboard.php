<?php
/**
 * Admin — network dashboard with teacher-style reports
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

$user = require_login(['admin', 'super_admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'activate' || $action === 'suspend') {
        $status = $action === 'activate' ? 'active' : 'suspended';
        if ($action === 'activate') {
            $pdo->prepare("UPDATE users SET status = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ? AND role != 'super_admin'")
                ->execute([$status, (int) $_POST['user_id']]);
        } else {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role != 'super_admin'")
                ->execute([$status, (int) $_POST['user_id']]);
        }
        flash('success', 'Teacher status updated.');
    }

    $redir = '/admin/dashboard.php';
    if (!empty($_POST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['date'])) {
        $redir .= '?date=' . urlencode((string) $_POST['date']);
    }
    redirect($redir);
}

$day = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
$isToday = $day === date('Y-m-d');
$dayFull = date('j M Y', strtotime($day));

$metrics = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM teachers t JOIN users u ON u.id = t.user_id WHERE u.status = 'active') AS active_teachers,
       (SELECT COUNT(*) FROM branches WHERE status = 'active') AS active_branches,
       (SELECT COUNT(*) FROM class_reviews WHERE has_issue_flag = 1 AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS open_issues_7d"
)->fetch();

$dayClasses = $pdo->prepare(
    'SELECT c.id, c.class_date, c.time_slot, c.status, c.course_category, c.subject, c.lecture_no, c.teacher_id,
            b.name AS branch_name, t.full_name AS teacher_name, t.medical_college, u.email AS teacher_email, u.status AS teacher_user_status, u.id AS teacher_user_id,
            cs.check_in_at, cs.teacher_class_start_at, cs.teacher_class_end_at,
            cs.manager_class_start_at, cs.manager_class_end_at, cs.manager_review_saved_at,
            cs.count_best, cs.count_good, cs.count_bad, cs.count_repeat,
            r.id AS review_id, r.has_issue_flag
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     JOIN teachers t ON t.id = c.teacher_id
     JOIN users u ON u.id = t.user_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     WHERE c.class_date = ?
     ORDER BY c.subject ASC, c.course_category ASC, c.time_slot ASC, b.name ASC, t.full_name ASC
     LIMIT 40'
);
$dayClasses->execute([$day]);
$classes = $dayClasses->fetchAll();

$dayAgg = $pdo->prepare(
    "SELECT
       COUNT(*) AS day_total,
       SUM(CASE WHEN cs.teacher_class_end_at IS NOT NULL OR c.status = 'completed' THEN 1 ELSE 0 END) AS day_done,
       SUM(CASE WHEN cs.teacher_class_start_at IS NOT NULL
                 AND cs.teacher_class_end_at IS NULL AND c.status <> 'completed' THEN 1 ELSE 0 END) AS day_live,
       SUM(CASE WHEN cs.check_in_at IS NOT NULL THEN 1 ELSE 0 END) AS on_site,
       SUM(CASE WHEN cs.count_best IS NOT NULL OR cs.count_good IS NOT NULL OR cs.manager_review_saved_at IS NOT NULL THEN 1 ELSE 0 END) AS reviewed,
       SUM(CASE WHEN r.has_issue_flag = 1 THEN 1 ELSE 0 END) AS flagged,
       COUNT(DISTINCT c.teacher_id) AS roster_teachers
     FROM classes c
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     WHERE c.class_date = ?"
);
$dayAgg->execute([$day]);
$agg = $dayAgg->fetch() ?: [];
$dayTotal = (int) ($agg['day_total'] ?? 0);
$dayDone = (int) ($agg['day_done'] ?? 0);
$dayLive = (int) ($agg['day_live'] ?? 0);
$onSite = (int) ($agg['on_site'] ?? 0);
$reviewed = (int) ($agg['reviewed'] ?? 0);
$flaggedDay = (int) ($agg['flagged'] ?? 0);
$rosterTeacherCount = (int) ($agg['roster_teachers'] ?? 0);
$todos = [];
foreach ($classes as $c) {
    $tEnd = !empty($c['teacher_class_end_at']);
    $tStart = !empty($c['teacher_class_start_at']);
    $mReview = $c['count_best'] !== null || $c['count_good'] !== null || !empty($c['manager_review_saved_at']);
    if (!$tEnd) {
        $todos[] = [
            'c' => $c,
            'task' => $tStart ? 'Live · awaiting end' : (!empty($c['check_in_at']) ? 'On site · not started' : 'Scheduled'),
        ];
    } elseif (!$mReview) {
        $todos[] = [
            'c' => $c,
            'task' => 'Waiting manager review',
        ];
    }
}
$pct = $dayTotal > 0 ? (int) round(($dayDone / $dayTotal) * 100) : 0;
$gaugePct = min(100, $pct ?: ($dayTotal > 0 ? 15 : 0));

/** Group classes by subject module (+ timer) — preview only */
$moduleOrder = medico_subjects();
$classesByModule = [];
foreach ($classes as $c) {
    $subj = trim((string) ($c['subject'] ?? ''));
    if ($subj === '') {
        $subj = 'Unassigned';
    }
    $timer = ($c['course_category'] ?? '') === '2nd_timer' ? '2nd Timer' : '1st Timer';
    $key = $subj . ' · ' . $timer;
    if (!isset($classesByModule[$key])) {
        $classesByModule[$key] = [
            'subject' => $subj,
            'timer' => $timer,
            'items' => [],
        ];
    }
    $classesByModule[$key]['items'][] = $c;
}
uksort($classesByModule, static function ($a, $b) use ($moduleOrder) {
    $sa = explode(' · ', $a)[0];
    $sb = explode(' · ', $b)[0];
    $ia = array_search($sa, $moduleOrder, true);
    $ib = array_search($sb, $moduleOrder, true);
    if ($ia === false) {
        $ia = 999;
    }
    if ($ib === false) {
        $ib = 999;
    }
    if ($ia !== $ib) {
        return $ia <=> $ib;
    }
    return strcmp($a, $b);
});

$upcomingByModule = [];
foreach ($classesByModule as $key => $mod) {
    $open = [];
    foreach ($mod['items'] as $c) {
        if (!empty($c['teacher_class_end_at']) || ($c['status'] ?? '') === 'completed') {
            continue;
        }
        $open[] = $c;
    }
    if ($open) {
        $upcomingByModule[$key] = [
            'subject' => $mod['subject'],
            'timer' => $mod['timer'],
            'items' => $open,
        ];
    }
}

$onRosterIds = [];
foreach ($classes as $c) {
    $tid = (int) ($c['teacher_id'] ?? 0);
    if ($tid > 0) {
        $onRosterIds[$tid] = true;
    }
}
if ($rosterTeacherCount < 1) {
    $rosterTeacherCount = count($onRosterIds);
}

$weekStart = date('Y-m-d', strtotime($day . ' -3 days'));
$weekDays = [];
$countStmt = $pdo->prepare(
    'SELECT class_date, COUNT(*) AS cnt,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS done
     FROM classes WHERE class_date BETWEEN ? AND ?
     GROUP BY class_date'
);
$countStmt->execute([$weekStart, date('Y-m-d', strtotime($weekStart . ' +6 days'))]);
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

$nav = admin_nav('overview');
extract($nav);
$pageTitle = 'Dashboard';
$pageSub = $dayFull;
$displayName = $user['email'];
$hidePageHead = true;
$topActions = '';
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <h1 class="dg-hello">Admin — network reports</h1>
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

        <div class="report-strip report-strip-dash">
          <div class="report-item">
            <span class="report-label">Classes</span>
            <span class="report-value"><?= (int) $dayTotal ?></span>
            <span class="report-hint"><?= h($dayFull) ?></span>
          </div>
          <div class="report-item">
            <span class="report-label">On site</span>
            <span class="report-value"><?= (int) $onSite ?></span>
            <span class="report-hint">Checked in</span>
          </div>
          <div class="report-item">
            <span class="report-label">Live</span>
            <span class="report-value"><?= (int) $dayLive ?></span>
            <span class="report-hint">In progress</span>
          </div>
          <div class="report-item">
            <span class="report-label">Done</span>
            <span class="report-value"><?= (int) $dayDone ?></span>
            <span class="report-hint"><?= (int) $pct ?>%</span>
          </div>
          <div class="report-item">
            <span class="report-label">Reviewed</span>
            <span class="report-value"><?= (int) $reviewed ?></span>
            <span class="report-hint">Manager entry</span>
          </div>
          <div class="report-item<?= $flaggedDay > 0 ? ' is-alert' : '' ?>">
            <span class="report-label">Flagged</span>
            <span class="report-value"><?= (int) $flaggedDay ?></span>
            <span class="report-hint">This day</span>
          </div>
        </div>

        <div class="dg-grid">
          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Day progress</h3>
              <span class="muted" style="font-size:.75rem;font-weight:700"><?= h($dayFull) ?></span>
            </div>
            <div class="dg-progress-label">Network · finished classes</div>
            <div class="dg-progress-track">
              <div class="dg-progress-fill" style="width:<?= (int) $pct ?>%"></div>
            </div>
            <div class="dg-progress-meta">
              <span><?= (int) $dayDone ?> / <?= (int) $dayTotal ?> finished</span>
              <span><?= (int) $pct ?>%</span>
            </div>
            <div class="dg-progress-meta" style="margin-top:.65rem">
              <span>Live <?= (int) $dayLive ?></span>
              <span>On site <?= (int) $onSite ?></span>
              <span>Reviewed <?= (int) $reviewed ?></span>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Modules</h3></div>
            <div class="dg-resource-list">
              <a class="dg-resource-item" href="/admin/classes.php?date=<?= h($day) ?>">
                <span class="dg-file-ico">CL</span>
                <div><strong>Classes</strong><span><?= (int) $dayTotal ?> slots · by subject</span></div>
              </a>
              <a class="dg-resource-item" href="/admin/teachers.php?date=<?= h($day) ?>">
                <span class="dg-file-ico">TR</span>
                <div><strong>Teachers</strong><span><?= (int) $rosterTeacherCount ?> on roster today</span></div>
              </a>
              <a class="dg-resource-item" href="/admin/reviews.php">
                <span class="dg-file-ico">RV</span>
                <div><strong>Reviews</strong><span>Teacher vs manager</span></div>
              </a>
              <a class="dg-resource-item" href="/admin/branches.php">
                <span class="dg-file-ico">BR</span>
                <div><strong>Branches</strong><span>Map · managers · + add</span></div>
              </a>
            </div>
          </section>

          <section class="dg-card">
            <div class="dg-card-head"><h3>Classes this week</h3></div>
            <div class="dg-bars">
              <?php foreach ($weekDays as $wd): ?>
                <a class="dg-bar-col<?= $wd['is_active'] ? ' is-active' : '' ?>" href="?date=<?= h($wd['date']) ?>" title="<?= (int) $wd['count'] ?> classes">
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
            <div class="dg-card-head"><h3>To-do · watch list</h3></div>
            <?php if (!$classes): ?>
              <div class="empty-state"><strong>No classes</strong>Nothing scheduled this day.</div>
            <?php elseif (!$todos): ?>
              <div class="empty-state"><strong>All caught up</strong>Day looks complete.</div>
            <?php else: ?>
              <ul class="dg-todo">
                <?php foreach (array_slice($todos, 0, 6) as $item): $c = $item['c']; ?>
                  <li>
                    <a href="/admin/review.php?class_id=<?= (int) $c['id'] ?>">
                      <i class="dg-check"></i>
                      <div>
                        <strong><?= h($c['teacher_name']) ?></strong>
                        <span><?= h($c['branch_name']) ?> · <?= h($item['task']) ?></span>
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

          <section class="dg-card dg-span-2">
            <div class="dg-card-head">
              <h3>Upcoming · open slots</h3>
              <a class="dg-link" href="/admin/classes.php?date=<?= h($day) ?>">Classes module →</a>
            </div>
            <?php
            $openPreview = [];
            foreach ($classes as $c) {
                if (!empty($c['teacher_class_end_at']) || ($c['status'] ?? '') === 'completed') {
                    continue;
                }
                $openPreview[] = $c;
                if (count($openPreview) >= 8) {
                    break;
                }
            }
            ?>
            <?php if (!$openPreview): ?>
              <div class="muted" style="font-size:.85rem;padding:.35rem 0">No open slots · open Classes in the sidebar.</div>
            <?php else: ?>
              <div class="dg-upcoming">
                <?php foreach ($openPreview as $c): ?>
                  <a class="dg-up-item" href="/admin/review.php?class_id=<?= (int) $c['id'] ?>">
                    <span><?= h(($c['subject'] ?: 'Class') . ' · ' . $c['teacher_name']) ?></span>
                    <time><?= h(format_time($c['time_slot'])) ?></time>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
