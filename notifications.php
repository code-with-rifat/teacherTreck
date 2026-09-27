<?php
/**
 * Activity / notification history — Designo shell for all roles
 */
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
ensure_notifications_schema();
$pdo = db();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all') {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
    flash('success', 'All notifications marked as read.');
    redirect('/teacherTreck/notifications.php');
}

$p = paginate_request(25);
$cSt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$cSt->execute([$uid]);
$total = (int) $cSt->fetchColumn();
$meta = paginate_meta($total, $p);

$st = $pdo->prepare(
    'SELECT * FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT ' . (int) $meta['per'] . ' OFFSET ' . (int) $meta['offset']
);
$st->execute([$uid]);
$items = $st->fetchAll();

if ($items) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
}

$role = (string) $user['role'];

if ($role === 'branch_manager') {
    require __DIR__ . '/includes/manager.php';
    $branch = manager_branch($pdo, $user);
    $nav = manager_nav('notifications');
    extract($nav);
    $displayName = $branch['name'];
} elseif ($role === 'teacher') {
    require __DIR__ . '/includes/teacher.php';
    $tSt = $pdo->prepare('SELECT full_name FROM teachers WHERE user_id = ?');
    $tSt->execute([$uid]);
    $tName = (string) ($tSt->fetchColumn() ?: $user['email']);
    $nav = teacher_nav('notifications', $tName);
    extract($nav);
} elseif ($role === 'admin' || $role === 'super_admin') {
    require __DIR__ . '/includes/admin.php';
    $nav = admin_nav('notifications');
    extract($nav);
    $displayName = $user['email'];
    if ($role === 'super_admin') {
        $navRole = 'Super Admin';
        $navLinks[] = [
            'id' => 'super',
            'href' => '/teacherTreck/super/dashboard.php',
            'icon' => '⚙',
            'label' => 'System',
        ];
    }
} else {
    $displayName = $user['email'];
    $navRole = 'User';
    $activeNav = 'notifications';
    $shellStyle = 'designo';
    $navLinks = [];
    $profileHref = '';
    $settingsHref = '';
}

$pageTitle = 'Activity';
$pageSub = 'Date & time history';
$hidePageHead = true;
$topActions = '';
require __DIR__ . '/includes/app_header.php';

$grouped = [];
foreach ($items as $n) {
    $dayKey = date('Y-m-d', strtotime($n['created_at']));
    $grouped[$dayKey][] = $n;
}
?>
        <h1 class="dg-hello">Activity</h1>
        <p class="dg-page-sub">Newest first · <?= (int) $total ?> total</p>
        <div class="dg-toolbar">
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="mark_all" />
            <button class="btn btn-secondary btn-sm" type="submit" style="width:auto">Mark all read</button>
          </form>
        </div>

        <section class="dg-card dg-activity-card">
          <?php if (!$items): ?>
            <div class="empty-state"><strong>No activity yet</strong>Class actions will show here with date &amp; time.</div>
          <?php else: ?>
            <div class="dg-activity">
              <?php foreach ($grouped as $day => $rows): ?>
                <div class="dg-activity-day">
                  <div class="dg-activity-day-label">
                    <?= $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('l, j M Y', strtotime($day))) ?>
                  </div>
                  <?php foreach ($rows as $n): ?>
                    <a class="dg-activity-item" href="<?= h(notification_link($n, $role)) ?>">
                      <div class="dg-activity-main">
                        <strong><?= h($n['title']) ?></strong>
                        <span><?= h($n['body']) ?></span>
                      </div>
                      <time><?= h(date('h:i A', strtotime($n['created_at']))) ?></time>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?= render_pager($meta) ?>
          <?php endif; ?>
        </section>
<?php require __DIR__ . '/includes/app_footer.php'; ?>
