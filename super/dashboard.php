<?php
/**
 * Super Admin — full control center
 */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['super_admin']);
$pdo = db();
$selfId = (int) $user['id'];

$tab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'users', 'branches', 'settings'], true)) {
    $tab = 'overview';
}

function super_setting_get(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return is_string($v) && $v !== '' ? $v : $default;
}

function super_setting_set(PDO $pdo, string $key, string $val, string $desc = ''): void
{
    $exists = $pdo->prepare('SELECT id FROM system_settings WHERE setting_key = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([$val, $key]);
    } else {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)'
        )->execute([$key, $val, $desc !== '' ? $desc : 'System setting']);
    }
}

function super_redir(string $tab = 'overview'): never
{
    redirect('/super/dashboard.php?tab=' . urlencode($tab));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $back = (string) ($_POST['tab'] ?? 'overview');
    if (!in_array($back, ['overview', 'users', 'branches', 'settings'], true)) {
        $back = 'overview';
    }

    if ($action === 'create_user') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $allowed = ['teacher', 'branch_manager', 'admin', 'super_admin'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8 || !in_array($role, $allowed, true)) {
            flash('error', 'Valid email, password (8+), and role required.');
        } else {
            $ex = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $ex->execute([$email]);
            if ($ex->fetch()) {
                flash('error', 'Email already exists.');
            } else {
                $pdo->prepare(
                    "INSERT INTO users (email, password_hash, role, status, email_verified_at)
                     VALUES (?, ?, ?, 'active', NOW())"
                )->execute([$email, password_hash($pass, PASSWORD_BCRYPT), $role]);
                $newUid = (int) $pdo->lastInsertId();
                notify_admins(
                    'New user · ' . $role,
                    '#' . $newUid . ' · ' . $email,
                    'user_created',
                    'user',
                    $newUid
                );
                flash('success', 'User created · ID #' . $newUid . ' · ' . $email);
            }
        }
        super_redir('users');
    }

    if ($action === 'update_user') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $newPass = (string) ($_POST['new_password'] ?? '');
        $allowedRoles = ['teacher', 'branch_manager', 'admin', 'super_admin'];
        $allowedStatus = ['active', 'inactive', 'suspended', 'pending'];

        if ($uid < 1) {
            flash('error', 'Invalid user.');
            super_redir('users');
        }
        if ($uid === $selfId && $role !== 'super_admin') {
            flash('error', 'You cannot change your own role away from Super Admin.');
            super_redir('users');
        }
        if ($uid === $selfId && $status !== 'active') {
            flash('error', 'You cannot deactivate your own account.');
            super_redir('users');
        }
        if (!in_array($role, $allowedRoles, true) || !in_array($status, $allowedStatus, true)) {
            flash('error', 'Invalid role or status.');
            super_redir('users');
        }

        $pdo->prepare('UPDATE users SET role = ?, status = ? WHERE id = ?')
            ->execute([$role, $status, $uid]);

        if ($newPass !== '') {
            if (strlen($newPass) < 8) {
                flash('error', 'Password must be at least 8 characters.');
                super_redir('users');
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPass, PASSWORD_BCRYPT), $uid]);
            flash('success', 'User #' . $uid . ' updated · password reset.');
        } else {
            flash('success', 'User #' . $uid . ' updated.');
        }
        super_redir('users');
    }

    if ($action === 'assign_manager') {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $mgrId = (int) ($_POST['user_id'] ?? 0);
        if ($branchId < 1 || $mgrId < 1) {
            flash('error', 'Branch and manager required.');
            super_redir('branches');
        }
        $m = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'branch_manager' AND status = 'active'");
        $m->execute([$mgrId]);
        if (!$m->fetch()) {
            flash('error', 'Invalid active branch manager.');
            super_redir('branches');
        }
        $pdo->prepare('UPDATE branches SET manager_user_id = NULL WHERE manager_user_id = ? AND id != ?')
            ->execute([$mgrId, $branchId]);
        $pdo->prepare('UPDATE branches SET manager_user_id = ?, setup_completed = 1 WHERE id = ?')
            ->execute([$mgrId, $branchId]);
        flash('success', 'Manager assigned to branch.');
        super_redir('branches');
    }

    if ($action === 'toggle_branch') {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($branchId > 0) {
            $pdo->prepare('UPDATE branches SET status = ? WHERE id = ?')->execute([$status, $branchId]);
            flash('success', 'Branch status updated.');
        }
        super_redir('branches');
    }

    if ($action === 'mail_smtp') {
        $fields = [
            'mail_from_email' => trim((string) ($_POST['from_email'] ?? '')),
            'mail_from_name' => trim((string) ($_POST['from_name'] ?? 'MEDICO')),
            'mail_smtp_host' => trim((string) ($_POST['smtp_host'] ?? 'smtp.gmail.com')),
            'mail_smtp_port' => (string) max(1, (int) ($_POST['smtp_port'] ?? 587)),
            'mail_smtp_encryption' => in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl'], true)
                ? (string) $_POST['smtp_encryption'] : 'tls',
            'mail_smtp_user' => trim((string) ($_POST['smtp_user'] ?? '')),
        ];
        foreach ($fields as $key => $val) {
            super_setting_set($pdo, $key, $val, 'Mail SMTP setting');
        }
        $pass = (string) ($_POST['smtp_pass'] ?? '');
        if ($pass !== '') {
            super_setting_set($pdo, 'mail_smtp_pass', $pass, 'Mail SMTP password');
        }
        flash('success', 'Email SMTP saved.');
        super_redir('settings');
    }

    if ($action === 'mail_test') {
        $to = strtolower(trim((string) ($_POST['test_email'] ?? $user['email'])));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Valid test email required.');
        } else {
            $code = (string) random_int(100000, 999999);
            $res = send_otp_email($to, $code, 'verify');
            flash($res['ok'] ? 'success' : 'error', $res['ok']
                ? 'Test code sent to ' . $to
                : ('Mail failed: ' . ($res['error'] ?? '')));
        }
        super_redir('settings');
    }

    super_redir($back);
}

/* ---- Data ---- */
$counts = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM users) AS users_total,
       (SELECT COUNT(*) FROM users WHERE status = 'active') AS users_active,
       (SELECT COUNT(*) FROM users WHERE role = 'admin') AS admins,
       (SELECT COUNT(*) FROM users WHERE role = 'branch_manager') AS managers,
       (SELECT COUNT(*) FROM users WHERE role = 'teacher') AS teachers,
       (SELECT COUNT(*) FROM branches) AS branches_total,
       (SELECT COUNT(*) FROM branches WHERE status = 'active') AS branches_active,
       (SELECT COUNT(*) FROM classes WHERE class_date = CURDATE()) AS classes_today,
       (SELECT COUNT(*) FROM class_reviews WHERE has_issue_flag = 1 AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS flags_7d"
)->fetch() ?: [];

$roleFilter = (string) ($_GET['role'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));

$userWhere = 'WHERE 1=1';
$userParams = [];
if (in_array($roleFilter, ['teacher', 'branch_manager', 'admin', 'super_admin'], true)) {
    $userWhere .= ' AND role = ?';
    $userParams[] = $roleFilter;
}
if (in_array($statusFilter, ['active', 'inactive', 'suspended', 'pending'], true)) {
    $userWhere .= ' AND status = ?';
    $userParams[] = $statusFilter;
}
if ($q !== '') {
    $userWhere .= ' AND (email LIKE ? OR CAST(id AS CHAR) = ?)';
    $userParams[] = '%' . $q . '%';
    $userParams[] = $q;
}

$userCountSt = $pdo->prepare("SELECT COUNT(*) FROM users $userWhere");
$userCountSt->execute($userParams);
$userTotal = (int) $userCountSt->fetchColumn();
$userP = paginate_request(20);
$userMeta = paginate_meta($userTotal, $userP);

$userListSt = $pdo->prepare(
    "SELECT id, email, role, status, last_login_at, created_at, email_verified_at
     FROM users $userWhere
     ORDER BY id DESC
     LIMIT " . (int) $userMeta['per'] . ' OFFSET ' . (int) $userMeta['offset']
);
$userListSt->execute($userParams);
$users = $userListSt->fetchAll();

$branches = $pdo->query(
    'SELECT b.*, u.email AS manager_email, u.id AS manager_id
     FROM branches b
     LEFT JOIN users u ON u.id = b.manager_user_id
     ORDER BY b.name ASC'
)->fetchAll();
$managers = $pdo->query(
    "SELECT id, email FROM users WHERE role = 'branch_manager' AND status = 'active' ORDER BY email"
)->fetchAll();

$mailCfg = mail_config();
$mailOk = mail_is_configured();

$pageTitle = 'Super Admin';
$pageSub = 'Control center';
$navRole = 'Super Admin';
$activeNav = 'system';
$displayName = 'Super Admin';
$shellStyle = 'designo';
$hidePageHead = true;
$navLinks = [
    ['id' => 'system', 'href' => '/super/dashboard.php', 'icon' => '⚙', 'label' => 'Control'],
    ['id' => 'admin', 'href' => '/admin/dashboard.php', 'icon' => '▣', 'label' => 'Admin'],
    ['id' => 'branches', 'href' => '/admin/branches.php', 'icon' => '⌂', 'label' => 'Branches'],
    ['id' => 'reviews', 'href' => '/admin/reviews.php', 'icon' => '◈', 'label' => 'Reviews'],
    ['id' => 'notifications', 'href' => '/notifications.php', 'icon' => '◔', 'label' => 'Activity'],
];
$mobileNavLinks = $navLinks;
require __DIR__ . '/../includes/app_header.php';

$roleLabel = static fn (string $r): string => match ($r) {
    'super_admin' => 'Super Admin',
    'branch_manager' => 'Manager',
    'admin' => 'Admin',
    'teacher' => 'Teacher',
    default => $r,
};
?>
        <div class="dg-teach-top">
          <div>
            <h1 class="dg-hello">Super Admin</h1>
            <p class="admin-page-sub" style="margin:.15rem 0 0">Full system control · users · branches · settings</p>
          </div>
        </div>

        <div class="sa-tabs">
          <a class="sa-tab<?= $tab === 'overview' ? ' is-on' : '' ?>" href="?tab=overview">Overview</a>
          <a class="sa-tab<?= $tab === 'users' ? ' is-on' : '' ?>" href="?tab=users">Users</a>
          <a class="sa-tab<?= $tab === 'branches' ? ' is-on' : '' ?>" href="?tab=branches">Branches</a>
          <a class="sa-tab<?= $tab === 'settings' ? ' is-on' : '' ?>" href="?tab=settings">Settings</a>
        </div>

<?php if ($tab === 'overview'): ?>
        <div class="report-strip report-strip-dash sa-kpi">
          <div class="report-item">
            <span class="report-label">Users</span>
            <span class="report-value"><?= (int) ($counts['users_total'] ?? 0) ?></span>
            <span class="report-hint"><?= (int) ($counts['users_active'] ?? 0) ?> active</span>
          </div>
          <div class="report-item">
            <span class="report-label">Teachers</span>
            <span class="report-value"><?= (int) ($counts['teachers'] ?? 0) ?></span>
            <span class="report-hint">Accounts</span>
          </div>
          <div class="report-item">
            <span class="report-label">Managers</span>
            <span class="report-value"><?= (int) ($counts['managers'] ?? 0) ?></span>
            <span class="report-hint">Branch logins</span>
          </div>
          <div class="report-item">
            <span class="report-label">Branches</span>
            <span class="report-value"><?= (int) ($counts['branches_total'] ?? 0) ?></span>
            <span class="report-hint"><?= (int) ($counts['branches_active'] ?? 0) ?> active</span>
          </div>
          <div class="report-item">
            <span class="report-label">Today</span>
            <span class="report-value"><?= (int) ($counts['classes_today'] ?? 0) ?></span>
            <span class="report-hint">Classes</span>
          </div>
          <div class="report-item<?= ((int) ($counts['flags_7d'] ?? 0)) > 0 ? ' is-alert' : '' ?>">
            <span class="report-label">Flags 7d</span>
            <span class="report-value"><?= (int) ($counts['flags_7d'] ?? 0) ?></span>
            <span class="report-hint">Review issues</span>
          </div>
        </div>

        <div class="sa-quick">
          <a class="sa-quick-card" href="?tab=users">
            <strong>Manage users</strong>
            <span>Create · role · status · password</span>
          </a>
          <a class="sa-quick-card" href="?tab=branches">
            <strong>Branches</strong>
            <span>Assign managers · activate</span>
          </a>
          <a class="sa-quick-card" href="?tab=settings">
            <strong>System settings</strong>
            <span>Email SMTP · mail test</span>
          </a>
          <a class="sa-quick-card" href="/admin/dashboard.php">
            <strong>Admin console</strong>
            <span>Classes · teachers · reviews</span>
          </a>
          <a class="sa-quick-card" href="/admin/branches.php?new=1">
            <strong>New branch + manager</strong>
            <span>Create login credentials</span>
          </a>
          <a class="sa-quick-card" href="/admin/reviews.php?flagged=1">
            <strong>Flagged reviews</strong>
            <span>Network issues</span>
          </a>
        </div>

        <section class="dg-card" style="margin-top:1rem">
          <div class="dg-card-head"><h3>Mail status</h3></div>
          <p class="sa-mail-status">
            SMTP:
            <strong class="<?= $mailOk ? 'is-ok' : 'is-bad' ?>"><?= $mailOk ? 'Ready' : 'Not configured' ?></strong>
            <?php if (!$mailOk): ?>
              · <a href="?tab=settings">Set up email</a>
            <?php endif; ?>
          </p>
        </section>

<?php elseif ($tab === 'users'): ?>
        <div class="br-layout sa-users-layout">
          <section class="dg-card">
            <div class="dg-card-head"><h3>Create user</h3></div>
            <form method="post" class="br-form">
              <input type="hidden" name="action" value="create_user" />
              <input type="hidden" name="tab" value="users" />
              <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="user@…" />
              </div>
              <div class="form-group">
                <label>Password</label>
                <input type="text" name="password" minlength="8" required placeholder="Min 8 characters" autocomplete="new-password" />
              </div>
              <div class="form-group">
                <label>Role</label>
                <select name="role">
                  <option value="branch_manager">Branch Manager</option>
                  <option value="admin">Admin</option>
                  <option value="teacher">Teacher</option>
                  <option value="super_admin">Super Admin</option>
                </select>
              </div>
              <button class="btn btn-primary btn-sm" type="submit" style="width:auto">Create user</button>
            </form>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>All users</h3>
              <span class="muted" style="font-size:.78rem;font-weight:650"><?= (int) $userTotal ?></span>
            </div>
            <form method="get" class="sa-filters">
              <input type="hidden" name="tab" value="users" />
              <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search email or ID…" />
              <select name="role">
                <option value="">All roles</option>
                <?php foreach (['teacher', 'branch_manager', 'admin', 'super_admin'] as $r): ?>
                  <option value="<?= h($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= h($roleLabel($r)) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="status">
                <option value="">All status</option>
                <?php foreach (['active', 'pending', 'inactive', 'suspended'] as $s): ?>
                  <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-secondary btn-sm" type="submit" style="width:auto">Filter</button>
            </form>

            <?php if (!$users): ?>
              <div class="empty-state"><strong>No users</strong>Try another filter.</div>
            <?php else: ?>
              <div class="sa-user-list">
                <?php foreach ($users as $u):
                    $uid = (int) $u['id'];
                    $isSelf = $uid === $selfId;
                ?>
                  <details class="sa-user">
                    <summary>
                      <div class="sa-user-main">
                        <strong><?= h($u['email']) ?></strong>
                        <span>#<?= $uid ?> · <?= h($roleLabel((string) $u['role'])) ?> · <?= h((string) $u['status']) ?></span>
                      </div>
                      <em><?= $isSelf ? 'You' : 'Edit' ?></em>
                    </summary>
                    <form method="post" class="sa-user-edit">
                      <input type="hidden" name="action" value="update_user" />
                      <input type="hidden" name="tab" value="users" />
                      <input type="hidden" name="user_id" value="<?= $uid ?>" />
                      <div class="form-row">
                        <div class="form-group">
                          <label>Role</label>
                          <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
                            <?php foreach (['teacher', 'branch_manager', 'admin', 'super_admin'] as $r): ?>
                              <option value="<?= h($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= h($roleLabel($r)) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <?php if ($isSelf): ?><input type="hidden" name="role" value="super_admin" /><?php endif; ?>
                        </div>
                        <div class="form-group">
                          <label>Status</label>
                          <select name="status" <?= $isSelf ? 'disabled' : '' ?>>
                            <?php foreach (['active', 'pending', 'inactive', 'suspended'] as $s): ?>
                              <option value="<?= h($s) ?>" <?= $u['status'] === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <?php if ($isSelf): ?><input type="hidden" name="status" value="active" /><?php endif; ?>
                        </div>
                      </div>
                      <div class="form-group">
                        <label>Reset password (optional)</label>
                        <input type="text" name="new_password" minlength="8" placeholder="Leave blank to keep" autocomplete="new-password" />
                      </div>
                      <p class="muted" style="font-size:.75rem;margin:0 0 .65rem">
                        Created <?= h(date('j M Y', strtotime((string) $u['created_at']))) ?>
                        <?php if (!empty($u['last_login_at'])): ?>
                          · Last login <?= h(date('j M Y H:i', strtotime((string) $u['last_login_at']))) ?>
                        <?php endif; ?>
                      </p>
                      <button class="btn btn-primary btn-sm" type="submit" style="width:auto">Save user</button>
                    </form>
                  </details>
                <?php endforeach; ?>
              </div>
              <?= render_pager($userMeta) ?>
            <?php endif; ?>
          </section>
        </div>

<?php elseif ($tab === 'branches'): ?>
        <div class="br-layout">
          <section class="dg-card">
            <div class="dg-card-head">
              <h3>Assign manager</h3>
              <a class="dg-link" href="/admin/branches.php?new=1">+ New branch</a>
            </div>
            <form method="post" class="br-form">
              <input type="hidden" name="action" value="assign_manager" />
              <input type="hidden" name="tab" value="branches" />
              <div class="form-group">
                <label>Branch</label>
                <select name="branch_id" required>
                  <option value="">Select…</option>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= h($b['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Manager login</label>
                <select name="user_id" required>
                  <option value="">Select…</option>
                  <?php foreach ($managers as $m): ?>
                    <option value="<?= (int) $m['id'] ?>">#<?= (int) $m['id'] ?> · <?= h($m['email']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-primary btn-sm" type="submit" style="width:auto">Assign</button>
            </form>
            <p class="muted" style="font-size:.8rem;margin:1rem 0 0">
              Full branch create + manager password →
              <a href="/admin/branches.php?new=1">Admin Branches</a>
            </p>
          </section>

          <section class="dg-card">
            <div class="dg-card-head">
              <h3>All branches</h3>
              <span class="muted" style="font-size:.78rem;font-weight:650"><?= count($branches) ?></span>
            </div>
            <?php if (!$branches): ?>
              <div class="empty-state"><strong>No branches</strong></div>
            <?php else: ?>
              <div class="br-list">
                <?php foreach ($branches as $b):
                    $active = ($b['status'] ?? '') === 'active';
                ?>
                  <div class="br-item sa-branch-row">
                    <div class="br-item-main">
                      <strong><?= h($b['name']) ?></strong>
                      <span><?= !empty($b['city']) ? h($b['city']) : '—' ?><?= !empty($b['is_outside_dhaka']) ? ' · Outside Dhaka' : '' ?></span>
                      <em>
                        <?php if (!empty($b['manager_id'])): ?>
                          Manager #<?= (int) $b['manager_id'] ?> · <?= h($b['manager_email']) ?>
                        <?php else: ?>
                          No manager
                        <?php endif; ?>
                      </em>
                    </div>
                    <div class="sa-branch-actions">
                      <span class="br-pill<?= $active ? ' is-ok' : '' ?>"><?= $active ? 'Active' : 'Off' ?></span>
                      <form method="post">
                        <input type="hidden" name="action" value="toggle_branch" />
                        <input type="hidden" name="tab" value="branches" />
                        <input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>" />
                        <input type="hidden" name="status" value="<?= $active ? 'inactive' : 'active' ?>" />
                        <button class="btn btn-secondary btn-sm" type="submit" style="width:auto">
                          <?= $active ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </form>
                      <a class="btn btn-secondary btn-sm" href="/admin/branches.php?edit=<?= (int) $b['id'] ?>" style="width:auto">Edit</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>

<?php else: /* settings */ ?>
        <section class="dg-card sa-settings">
          <div class="dg-card-head">
            <h3>Email SMTP</h3>
            <span class="sa-pill<?= $mailOk ? ' is-ok' : ' is-bad' ?>"><?= $mailOk ? 'Ready' : 'Not set' ?></span>
          </div>
          <p class="muted" style="font-size:.85rem;margin:0 0 1rem">
            Login OTP, password reset ও system mail এর জন্য।
          </p>
          <form method="post" class="br-form">
            <input type="hidden" name="action" value="mail_smtp" />
            <input type="hidden" name="tab" value="settings" />
            <div class="form-row">
              <div class="form-group">
                <label>From email</label>
                <input type="email" name="from_email" required value="<?= h((string) ($mailCfg['from_email'] ?? super_setting_get($pdo, 'mail_from_email'))) ?>" />
              </div>
              <div class="form-group">
                <label>From name</label>
                <input type="text" name="from_name" value="<?= h((string) ($mailCfg['from_name'] ?? 'MEDICO')) ?>" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>SMTP host</label>
                <input type="text" name="smtp_host" value="<?= h((string) ($mailCfg['smtp_host'] ?? 'smtp.gmail.com')) ?>" />
              </div>
              <div class="form-group">
                <label>Port</label>
                <input type="number" name="smtp_port" value="<?= h((string) ($mailCfg['smtp_port'] ?? 587)) ?>" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Encryption</label>
                <?php $enc = (string) ($mailCfg['smtp_encryption'] ?? 'tls'); ?>
                <select name="smtp_encryption">
                  <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                  <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                </select>
              </div>
              <div class="form-group">
                <label>SMTP user</label>
                <input type="email" name="smtp_user" value="<?= h((string) ($mailCfg['smtp_user'] ?? '')) ?>" />
              </div>
            </div>
            <div class="form-group">
              <label>App password</label>
              <input type="password" name="smtp_pass" value="" placeholder="<?= !empty($mailCfg['smtp_pass']) ? '•••••••• (blank = keep)' : 'Gmail App Password' ?>" autocomplete="new-password" />
            </div>
            <button class="btn btn-primary btn-sm" type="submit" style="width:auto">Save SMTP</button>
          </form>
          <form method="post" class="sa-test-mail">
            <input type="hidden" name="action" value="mail_test" />
            <input type="hidden" name="tab" value="settings" />
            <input type="email" name="test_email" value="<?= h($user['email']) ?>" />
            <button class="btn btn-secondary btn-sm" type="submit" style="width:auto">Send test email</button>
          </form>
        </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
