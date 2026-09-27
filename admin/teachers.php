<?php
/**
 * Admin — teachers by subject module (day roster + directory)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';
ensure_class_curriculum_schema();

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
    $redir = '/teacherTreck/admin/teachers.php';
    if (!empty($_POST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['date'])) {
        $redir .= '?date=' . urlencode((string) $_POST['date']);
    }
    redirect($redir);
}

$navDay = admin_day_nav();
$day = $navDay['day'];
$dayFull = $navDay['dayFull'];

$dayRows = $pdo->prepare(
    'SELECT c.teacher_id, c.subject, c.time_slot, c.course_category, c.lecture_no,
            b.name AS branch_name,
            t.full_name, t.medical_college, u.email, u.status AS user_status, u.id AS user_id
     FROM classes c
     JOIN branches b ON b.id = c.branch_id
     JOIN teachers t ON t.id = c.teacher_id
     JOIN users u ON u.id = t.user_id
     WHERE c.class_date = ?
     ORDER BY c.subject ASC, t.full_name ASC'
);
$dayRows->execute([$day]);
$dayClasses = $dayRows->fetchAll();

$moduleOrder = medico_subjects();
$byModule = [];
$onRoster = [];
foreach ($dayClasses as $c) {
    $subj = trim((string) ($c['subject'] ?? '')) ?: 'Unassigned';
    $tid = (int) $c['teacher_id'];
    $onRoster[$tid] = true;
    if (!isset($byModule[$subj][$tid])) {
        $byModule[$subj][$tid] = [
            'full_name' => $c['full_name'],
            'email' => $c['email'],
            'medical_college' => $c['medical_college'],
            'user_status' => $c['user_status'],
            'user_id' => (int) $c['user_id'],
            'slots' => 0,
            'branches' => [],
            'timers' => [],
        ];
    }
    $byModule[$subj][$tid]['slots']++;
    $bn = (string) $c['branch_name'];
    if ($bn !== '' && !in_array($bn, $byModule[$subj][$tid]['branches'], true)) {
        $byModule[$subj][$tid]['branches'][] = $bn;
    }
    $timer = ($c['course_category'] ?? '') === '2nd_timer' ? '2nd' : '1st';
    if (!in_array($timer, $byModule[$subj][$tid]['timers'], true)) {
        $byModule[$subj][$tid]['timers'][] = $timer;
    }
}
uksort($byModule, static function ($a, $b) use ($moduleOrder) {
    $ia = array_search($a, $moduleOrder, true);
    $ib = array_search($b, $moduleOrder, true);
    if ($ia === false) {
        $ia = 999;
    }
    if ($ib === false) {
        $ib = 999;
    }
    return $ia <=> $ib ?: strcmp($a, $b);
});

/* Day roster: paginate flattened teacher-module rows */
$rosterFlat = [];
foreach ($byModule as $subj => $rows) {
    foreach ($rows as $tid => $t) {
        $rosterFlat[] = ['subject' => $subj, 'tid' => $tid, 't' => $t];
    }
}
$rosterTotal = count($rosterFlat);
$rosterP = paginate_request(25, 'rpage');
$rosterMeta = paginate_meta($rosterTotal, $rosterP);
$rosterPage = array_slice($rosterFlat, $rosterMeta['offset'], $rosterMeta['per']);
$byModulePage = [];
foreach ($rosterPage as $row) {
    $byModulePage[$row['subject']][$row['tid']] = $row['t'];
}

$allTeachersTotal = (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$onRosterIds = array_map('intval', array_keys($onRoster));
$otherParams = [];
$otherWhere = '';
if ($onRosterIds) {
    $ph = implode(',', array_fill(0, count($onRosterIds), '?'));
    $otherWhere = " WHERE t.id NOT IN ($ph)";
    $otherParams = $onRosterIds;
}
$otherCountSt = $pdo->prepare('SELECT COUNT(*) FROM teachers t' . $otherWhere);
$otherCountSt->execute($otherParams);
$otherTotal = (int) $otherCountSt->fetchColumn();
$dirP = paginate_request(20, 'page');
$dirMeta = paginate_meta($otherTotal, $dirP);
$otherSt = $pdo->prepare(
    'SELECT t.*, u.email, u.status AS user_status, u.id AS user_id
     FROM teachers t JOIN users u ON u.id = t.user_id' . $otherWhere . '
     ORDER BY t.full_name
     LIMIT ' . (int) $dirMeta['per'] . ' OFFSET ' . (int) $dirMeta['offset']
);
$otherSt->execute($otherParams);
$otherTeachers = $otherSt->fetchAll();

$nav = admin_nav('teachers');
extract($nav);
$pageTitle = 'Teachers';
$pageSub = 'By module · ' . $dayFull;
$displayName = $user['email'];
$hidePageHead = true;
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <h1 class="dg-hello">Teachers · by module</h1>
          <div class="dg-toolbar">
            <?php if (!$navDay['isToday']): ?>
              <a class="btn btn-secondary btn-sm" href="?date=<?= h(date('Y-m-d')) ?>">Today</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="?date=<?= h($navDay['prev']) ?>">←</a>
            <a class="btn btn-secondary btn-sm" href="?date=<?= h($navDay['next']) ?>">→</a>
            <form method="get" style="margin:0">
              <input type="date" name="date" value="<?= h($day) ?>" onchange="this.form.submit()" />
            </form>
          </div>
        </div>

        <div class="report-strip report-strip-dash" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <div class="report-item">
            <span class="report-label">Modules</span>
            <span class="report-value"><?= count($byModule) ?></span>
            <span class="report-hint"><?= h($dayFull) ?></span>
          </div>
          <div class="report-item">
            <span class="report-label">On roster</span>
            <span class="report-value"><?= count($onRoster) ?></span>
            <span class="report-hint">Teaching today</span>
          </div>
          <div class="report-item">
            <span class="report-label">Directory</span>
            <span class="report-value"><?= (int) $allTeachersTotal ?></span>
            <span class="report-hint">All teachers</span>
          </div>
        </div>

        <section class="dg-card" style="margin-top:1rem">
          <div class="dg-card-head">
            <h3>Day roster · modules</h3>
          </div>
          <?php if (!$byModulePage): ?>
            <div class="empty-state"><strong>No teachers on roster</strong>No classes assigned this day.</div>
          <?php else: ?>
            <div class="admin-module-stack">
              <?php foreach ($byModulePage as $subj => $rows): ?>
                <div class="admin-module-block">
                  <div class="admin-module-head">
                    <strong><?= h($subj) ?></strong>
                    <em><?= count($rows) ?> teacher<?= count($rows) === 1 ? '' : 's' ?></em>
                  </div>
                  <div class="table-wrap admin-table-wrap">
                    <table class="data admin-clean-table">
                      <thead><tr><th>Name</th><th>Branch</th><th>Timer</th><th>Slots</th><th>Status</th><th></th></tr></thead>
                      <tbody>
                      <?php foreach ($rows as $t): ?>
                        <tr>
                          <td>
                            <strong><?= h($t['full_name']) ?></strong>
                            <div class="muted" style="font-size:.75rem"><?= h($t['email']) ?></div>
                          </td>
                          <td><?= h(implode(', ', $t['branches']) ?: '—') ?></td>
                          <td><?= h(implode(' · ', $t['timers'])) ?></td>
                          <td><?= (int) $t['slots'] ?></td>
                          <td><?= badge($t['user_status']) ?></td>
                          <td class="col-actions">
                            <form method="post">
                              <input type="hidden" name="date" value="<?= h($day) ?>" />
                              <input type="hidden" name="user_id" value="<?= (int) $t['user_id'] ?>" />
                              <?php if ($t['user_status'] !== 'active'): ?>
                                <input type="hidden" name="action" value="activate" />
                                <button class="btn btn-secondary btn-sm" type="submit">Activate</button>
                              <?php else: ?>
                                <input type="hidden" name="action" value="suspend" />
                                <button class="btn btn-ghost btn-sm" type="submit">Suspend</button>
                              <?php endif; ?>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?= render_pager($rosterMeta) ?>
          <?php endif; ?>
        </section>

        <?php if ($otherTotal > 0): ?>
          <section class="dg-card is-fit" style="margin-top:1rem">
            <div class="dg-card-head">
              <h3>Not on this day</h3>
              <span class="muted" style="font-size:.78rem;font-weight:600"><?= (int) $otherTotal ?></span>
            </div>
            <div class="table-wrap admin-table-wrap">
              <table class="data admin-clean-table">
                <thead><tr><th>Name</th><th>College</th><th>Email</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($otherTeachers as $t): ?>
                  <tr>
                    <td><?= h($t['full_name']) ?></td>
                    <td><?= h($t['medical_college']) ?></td>
                    <td><?= h($t['email']) ?></td>
                    <td><?= badge($t['user_status']) ?></td>
                    <td class="col-actions">
                      <form method="post">
                        <input type="hidden" name="date" value="<?= h($day) ?>" />
                        <input type="hidden" name="user_id" value="<?= (int) $t['user_id'] ?>" />
                        <?php if ($t['user_status'] !== 'active'): ?>
                          <input type="hidden" name="action" value="activate" />
                          <button class="btn btn-secondary btn-sm" type="submit">Activate</button>
                        <?php else: ?>
                          <input type="hidden" name="action" value="suspend" />
                          <button class="btn btn-ghost btn-sm" type="submit">Suspend</button>
                        <?php endif; ?>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?= render_pager($dirMeta) ?>
          </section>
        <?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
