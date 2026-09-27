<?php
/**
 * Admin — network classes (schedule board)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';
ensure_class_curriculum_schema();

$user = require_login(['admin', 'super_admin']);
$pdo = db();
$navDay = admin_day_nav();
$day = $navDay['day'];
$dayFull = $navDay['dayFull'];
$dayShort = date('D, j M', strtotime($day));

$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'live', 'open', 'done', 'flagged'], true)) {
    $status = 'all';
}
$branchId = (int) ($_GET['branch'] ?? 0);
$subject = trim((string) ($_GET['subject'] ?? ''));
$view = (string) ($_GET['view'] ?? 'schedule');
if (!in_array($view, ['schedule', 'module', 'branch'], true)) {
    $view = 'schedule';
}

$branches = $pdo->query(
    "SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC"
)->fetchAll();
$subjects = medico_subjects();

$where = 'WHERE c.class_date = ?';
$params = [$day];
if ($branchId > 0) {
    $where .= ' AND c.branch_id = ?';
    $params[] = $branchId;
}
if ($subject !== '' && in_array($subject, $subjects, true)) {
    $where .= ' AND c.subject = ?';
    $params[] = $subject;
}

$baseFrom = 'FROM classes c
     JOIN branches b ON b.id = c.branch_id
     JOIN teachers t ON t.id = c.teacher_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     ' . $where;

$statusSql = [
    'live' => " AND cs.teacher_class_start_at IS NOT NULL
                AND cs.teacher_class_end_at IS NULL AND c.status <> 'completed'",
    'open' => " AND cs.teacher_class_start_at IS NULL
                AND cs.teacher_class_end_at IS NULL AND c.status <> 'completed'",
    'done' => " AND (cs.teacher_class_end_at IS NOT NULL OR c.status = 'completed')",
    'flagged' => ' AND r.has_issue_flag = 1',
];

$countAllSt = $pdo->prepare('SELECT COUNT(*) ' . $baseFrom);
$countAllSt->execute($params);
$totalAll = (int) $countAllSt->fetchColumn();

$kpiSt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN cs.teacher_class_end_at IS NOT NULL OR c.status = \'completed\' THEN 1 ELSE 0 END) AS done_cnt,
        SUM(CASE WHEN cs.teacher_class_start_at IS NOT NULL
                  AND cs.teacher_class_end_at IS NULL AND c.status <> \'completed\' THEN 1 ELSE 0 END) AS live_cnt,
        SUM(CASE WHEN r.has_issue_flag = 1 THEN 1 ELSE 0 END) AS flag_cnt
     ' . $baseFrom
);
$kpiSt->execute($params);
$kpi = $kpiSt->fetch() ?: [];
$doneCount = (int) ($kpi['done_cnt'] ?? 0);
$liveCount = (int) ($kpi['live_cnt'] ?? 0);
$flagCount = (int) ($kpi['flag_cnt'] ?? 0);
$openCount = max(0, $totalAll - $doneCount - $liveCount);

$listWhere = $where . ($statusSql[$status] ?? '');
$listFrom = 'FROM classes c
     JOIN branches b ON b.id = c.branch_id
     JOIN teachers t ON t.id = c.teacher_id
     LEFT JOIN class_sessions cs ON cs.class_id = c.id
     LEFT JOIN class_reviews r ON r.class_id = c.id
     ' . $listWhere;

$countSt = $pdo->prepare('SELECT COUNT(*) ' . $listFrom);
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();

$p = paginate_request(40);
$meta = paginate_meta($total, $p);

$order = match ($view) {
    'module' => 'c.subject ASC, c.course_category ASC, c.time_slot ASC, b.name ASC',
    'branch' => 'b.name ASC, c.time_slot ASC, t.full_name ASC',
    default => 'c.time_slot ASC, b.name ASC, t.full_name ASC',
};

$rows = $pdo->prepare(
    'SELECT c.id, c.time_slot, c.status, c.course_category, c.subject, c.lecture_no, c.branch_id,
            b.name AS branch_name, t.full_name AS teacher_name,
            cs.check_in_at, cs.teacher_class_start_at, cs.teacher_class_end_at,
            r.has_issue_flag
     ' . $listFrom . '
     ORDER BY ' . $order . '
     LIMIT ' . (int) $meta['per'] . ' OFFSET ' . (int) $meta['offset']
);
$rows->execute($params);
$classes = $rows->fetchAll();

/** @return array<string, array{label:string, items:list}> */
function admin_class_groups(array $classes, string $view): array
{
    if ($view === 'schedule') {
        return ['__all' => ['label' => '', 'items' => $classes]];
    }
    $groups = [];
    foreach ($classes as $c) {
        if ($view === 'branch') {
            $key = 'b' . (int) $c['branch_id'];
            $label = (string) $c['branch_name'];
        } else {
            $subj = trim((string) ($c['subject'] ?? '')) ?: 'Unassigned';
            $timer = ($c['course_category'] ?? '') === '2nd_timer' ? '2nd Timer' : '1st Timer';
            $key = $subj . '|' . $timer;
            $label = $subj . ' · ' . $timer;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $label, 'items' => []];
        }
        $groups[$key]['items'][] = $c;
    }
    return $groups;
}

$groups = admin_class_groups($classes, $view);

function admin_classes_qs(array $extra = []): string
{
    $q = array_merge([
        'date' => $_GET['date'] ?? date('Y-m-d'),
        'status' => $_GET['status'] ?? 'all',
        'branch' => $_GET['branch'] ?? '',
        'subject' => $_GET['subject'] ?? '',
        'view' => $_GET['view'] ?? 'schedule',
    ], $extra);
    unset($q['page']);
    if (($q['status'] ?? 'all') === 'all' || ($q['status'] ?? '') === '') {
        unset($q['status']);
    }
    if (($q['branch'] ?? '') === '' || (string) ($q['branch'] ?? '') === '0') {
        unset($q['branch']);
    }
    if (($q['subject'] ?? '') === '') {
        unset($q['subject']);
    }
    if (($q['view'] ?? 'schedule') === 'schedule') {
        unset($q['view']);
    }
    if (($q['date'] ?? '') === date('Y-m-d')) {
        unset($q['date']);
    }
    $built = http_build_query($q);
    return $built !== '' ? '?' . $built : '?';
}

$nav = admin_nav('classes');
extract($nav);
$pageTitle = 'Classes';
$pageSub = 'Network schedule';
$displayName = $user['email'];
$hidePageHead = true;
require __DIR__ . '/../includes/app_header.php';

$statusFilters = [
    'all' => ['All', $totalAll],
    'live' => ['Live', $liveCount],
    'open' => ['Open', $openCount],
    'done' => ['Done', $doneCount],
    'flagged' => ['Flagged', $flagCount],
];
$viewFilters = [
    'schedule' => 'Schedule',
    'module' => 'By module',
    'branch' => 'By branch',
];
?>
        <div class="dg-teach-top">
          <div>
            <h1 class="dg-hello">Classes</h1>
            <p class="admin-page-sub" style="margin:0.15rem 0 0"><?= h($dayShort) ?> · who · when · where</p>
          </div>
          <div class="dg-toolbar ac-day-nav">
            <?php if (!$navDay['isToday']): ?>
              <a class="btn btn-secondary btn-sm" href="<?= h(admin_classes_qs(['date' => date('Y-m-d'), 'page' => null])) ?>">Today</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="<?= h(admin_classes_qs(['date' => $navDay['prev'], 'page' => null])) ?>" aria-label="Previous day">←</a>
            <form method="get" class="ac-date-form">
              <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= h($status) ?>" /><?php endif; ?>
              <?php if ($branchId > 0): ?><input type="hidden" name="branch" value="<?= (int) $branchId ?>" /><?php endif; ?>
              <?php if ($subject !== ''): ?><input type="hidden" name="subject" value="<?= h($subject) ?>" /><?php endif; ?>
              <?php if ($view !== 'schedule'): ?><input type="hidden" name="view" value="<?= h($view) ?>" /><?php endif; ?>
              <input type="date" name="date" value="<?= h($day) ?>" onchange="this.form.submit()" />
            </form>
            <a class="btn btn-secondary btn-sm" href="<?= h(admin_classes_qs(['date' => $navDay['next'], 'page' => null])) ?>" aria-label="Next day">→</a>
          </div>
        </div>

        <div class="ac-bar">
          <div class="dg-toolbar admin-filter-bar ac-status">
            <?php foreach ($statusFilters as $key => [$label, $cnt]): ?>
              <a class="admin-filter<?= $status === $key ? ' is-on' : '' ?>"
                 href="<?= h(admin_classes_qs(['status' => $key, 'page' => null])) ?>">
                <?= h($label) ?>
                <em><?= (int) $cnt ?></em>
              </a>
            <?php endforeach; ?>
          </div>
          <form method="get" class="ac-filters">
            <input type="hidden" name="date" value="<?= h($day) ?>" />
            <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= h($status) ?>" /><?php endif; ?>
            <?php if ($view !== 'schedule'): ?><input type="hidden" name="view" value="<?= h($view) ?>" /><?php endif; ?>
            <select name="branch" onchange="this.form.submit()" aria-label="Branch">
              <option value="">All branches</option>
              <?php foreach ($branches as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>>
                  <?= h($b['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="subject" onchange="this.form.submit()" aria-label="Subject">
              <option value="">All subjects</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?= h($s) ?>" <?= $subject === $s ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>

        <div class="dg-toolbar admin-filter-bar ac-views">
          <?php foreach ($viewFilters as $key => $label): ?>
            <a class="admin-filter<?= $view === $key ? ' is-on' : '' ?>"
               href="<?= h(admin_classes_qs(['view' => $key, 'page' => null])) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if (!$classes): ?>
          <section class="dg-card is-fit" style="margin-top:0.85rem">
            <div class="empty-state">
              <strong>No classes<?= $status !== 'all' || $branchId || $subject !== '' ? ' for this filter' : ' this day' ?></strong>
              Try another date, branch, or clear filters.
            </div>
          </section>
        <?php else: ?>
          <section class="ac-board">
            <div class="ac-board-head">
              <span>Time</span>
              <span>Teacher</span>
              <span>Class</span>
              <span>Branch</span>
              <span>Status</span>
            </div>
            <?php foreach ($groups as $g): ?>
              <?php if ($g['label'] !== ''): ?>
                <div class="ac-group-label"><?= h($g['label']) ?> · <?= count($g['items']) ?></div>
              <?php endif; ?>
              <?php foreach ($g['items'] as $c):
                  $done = !empty($c['teacher_class_end_at']) || ($c['status'] ?? '') === 'completed';
                  $live = !empty($c['teacher_class_start_at']) && !$done;
                  $flag = !empty($c['has_issue_flag']);
                  $onSite = !empty($c['check_in_at']);
                  if ($flag) {
                      $stLabel = 'Flagged';
                      $stClass = 'is-flag';
                  } elseif ($done) {
                      $stLabel = 'Done';
                      $stClass = 'is-done';
                  } elseif ($live) {
                      $stLabel = 'Live';
                      $stClass = 'is-live';
                  } elseif ($onSite) {
                      $stLabel = 'On site';
                      $stClass = 'is-site';
                  } else {
                      $stLabel = 'Open';
                      $stClass = 'is-open';
                  }
                  $timer = ($c['course_category'] ?? '') === '2nd_timer' ? '2nd' : '1st';
                  $classBits = [];
                  if (!empty($c['subject'])) {
                      $classBits[] = $c['subject'];
                  }
                  $classBits[] = $timer;
                  if (!empty($c['lecture_no'])) {
                      $classBits[] = 'L' . (int) $c['lecture_no'];
                  }
              ?>
                <a class="ac-row <?= h($stClass) ?>" href="/teacher-traking/admin/review.php?class_id=<?= (int) $c['id'] ?>">
                  <span class="ac-time"><?= h(format_time($c['time_slot'])) ?></span>
                  <span class="ac-teacher"><?= h($c['teacher_name']) ?></span>
                  <span class="ac-class"><?= h(implode(' · ', $classBits)) ?></span>
                  <span class="ac-branch"><?= h($c['branch_name']) ?></span>
                  <span class="ac-status <?= h($stClass) ?>"><?= h($stLabel) ?></span>
                </a>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </section>
          <?= render_pager($meta) ?>
        <?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
