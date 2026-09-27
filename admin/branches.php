<?php
/**
 * Admin — branches + create manager login credentials
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

$user = require_login(['admin', 'super_admin']);
ensure_branch_contact_schema();
$pdo = db();
$showNew = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_branch') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? 'Dhaka'));
        $address = trim((string) ($_POST['address'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $branchEmail = trim((string) ($_POST['branch_email'] ?? ''));
        $mgrEmail = strtolower(trim((string) ($_POST['manager_email'] ?? '')));
        $mgrPass = (string) ($_POST['manager_password'] ?? '');
        $outside = !empty($_POST['is_outside_dhaka']) ? 1 : 0;

        if ($name === '') {
            flash('error', 'Branch name required.');
            redirect('/admin/branches.php?new=1');
        }
        if (!filter_var($mgrEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Valid manager login email required.');
            redirect('/admin/branches.php?new=1');
        }
        if (strlen($mgrPass) < 8) {
            flash('error', 'Manager password must be at least 8 characters.');
            redirect('/admin/branches.php?new=1');
        }
        if ($branchEmail !== '' && !filter_var($branchEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid branch contact email.');
            redirect('/admin/branches.php?new=1');
        }

        /* Auto code for DB uniqueness — not shown to admin */
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?: 'BR');
        $slug = substr($slug, 0, 12);
        $code = $slug . '-' . strtoupper(bin2hex(random_bytes(2)));
        $tries = 0;
        while ($tries < 8) {
            $ex = $pdo->prepare('SELECT id FROM branches WHERE code = ?');
            $ex->execute([$code]);
            if (!$ex->fetch()) {
                break;
            }
            $code = $slug . '-' . strtoupper(bin2hex(random_bytes(2)));
            $tries++;
        }

        $emailEx = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $emailEx->execute([$mgrEmail]);
        if ($emailEx->fetch()) {
            flash('error', 'Manager email already used. Pick another login email.');
            redirect('/admin/branches.php?new=1');
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO users (email, password_hash, role, status, email_verified_at)
                 VALUES (?, ?, 'branch_manager', 'active', NOW())"
            )->execute([$mgrEmail, password_hash($mgrPass, PASSWORD_BCRYPT)]);
            $managerId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO branches (name, code, city, address, phone, email, is_outside_dhaka, status, setup_completed, manager_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, ?)"
            )->execute([
                $name,
                $code,
                $city !== '' ? $city : 'Dhaka',
                $address,
                $phone !== '' ? $phone : null,
                $branchEmail !== '' ? $branchEmail : null,
                $outside,
                $managerId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $pdo->commit();
            notify_admins(
                'New branch created · ' . $name,
                'Manager #' . $managerId . ' · ' . $mgrEmail
                    . ($outside ? ' · Outside Dhaka' : '')
                    . ($city !== '' ? ' · ' . $city : ''),
                'branch_created',
                'branch',
                $newId
            );
            flash(
                'success',
                'Branch created. Manager login — User ID #' . $managerId . ' · ' . $mgrEmail . ' (password you set).'
            );
            redirect('/admin/branches.php?edit=' . $newId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Could not create branch. Try again.');
            redirect('/admin/branches.php?new=1');
        }
    }

    if ($action === 'save_branch') {
        $id = (int) ($_POST['branch_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? 'Dhaka'));
        $outside = !empty($_POST['is_outside_dhaka']) ? 1 : 0;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $managerId = (int) ($_POST['manager_user_id'] ?? 0);
        $newMgrEmail = strtolower(trim((string) ($_POST['new_manager_email'] ?? '')));
        $newMgrPass = (string) ($_POST['new_manager_password'] ?? '');
        $resetPass = (string) ($_POST['reset_manager_password'] ?? '');

        if ($id < 1 || $name === '') {
            flash('error', 'Branch name required.');
            redirect('/admin/branches.php');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid branch email.');
            redirect('/admin/branches.php?edit=' . $id);
        }

        /* Create brand-new manager login for this branch */
        if ($newMgrEmail !== '' || $newMgrPass !== '') {
            if (!filter_var($newMgrEmail, FILTER_VALIDATE_EMAIL) || strlen($newMgrPass) < 8) {
                flash('error', 'New manager needs valid email + password (8+).');
                redirect('/admin/branches.php?edit=' . $id);
            }
            $emailEx = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $emailEx->execute([$newMgrEmail]);
            if ($emailEx->fetch()) {
                flash('error', 'That login email already exists.');
                redirect('/admin/branches.php?edit=' . $id);
            }
            $pdo->prepare(
                "INSERT INTO users (email, password_hash, role, status, email_verified_at)
                 VALUES (?, ?, 'branch_manager', 'active', NOW())"
            )->execute([$newMgrEmail, password_hash($newMgrPass, PASSWORD_BCRYPT)]);
            $managerId = (int) $pdo->lastInsertId();
        } elseif ($managerId > 0) {
            $m = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'branch_manager' AND status = 'active'");
            $m->execute([$managerId]);
            if (!$m->fetch()) {
                flash('error', 'Invalid branch manager.');
                redirect('/admin/branches.php?edit=' . $id);
            }
        }

        if ($managerId > 0) {
            $pdo->prepare('UPDATE branches SET manager_user_id = NULL WHERE manager_user_id = ? AND id != ?')
                ->execute([$managerId, $id]);
        }

        if ($resetPass !== '' && $managerId > 0) {
            if (strlen($resetPass) < 8) {
                flash('error', 'Reset password must be at least 8 characters.');
                redirect('/admin/branches.php?edit=' . $id);
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = \'branch_manager\'')
                ->execute([password_hash($resetPass, PASSWORD_BCRYPT), $managerId]);
        }

        $pdo->prepare(
            'UPDATE branches
             SET name = ?, phone = ?, email = ?, address = ?, city = ?,
                 is_outside_dhaka = ?, status = ?, manager_user_id = ?, setup_completed = 1
             WHERE id = ?'
        )->execute([
            $name,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
            $address,
            $city,
            $outside,
            $status,
            $managerId > 0 ? $managerId : null,
            $id,
        ]);

        if ($newMgrEmail !== '' && $managerId > 0) {
            flash('success', 'Branch saved. New manager · User ID #' . $managerId . ' · ' . $newMgrEmail);
        } elseif ($resetPass !== '' && $managerId > 0) {
            flash('success', 'Branch saved. Manager password updated · User ID #' . $managerId);
        } else {
            flash('success', 'Branch saved.');
        }
        redirect('/admin/branches.php?edit=' . $id);
    }

    redirect('/admin/branches.php');
}

$branchTotal = (int) $pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
$p = paginate_request(15);
$meta = paginate_meta($branchTotal, $p);

$branches = $pdo->query(
    "SELECT b.*, u.email AS manager_email, u.id AS manager_id
     FROM branches b
     LEFT JOIN users u ON u.id = b.manager_user_id
     ORDER BY b.name ASC
     LIMIT " . (int) $meta['per'] . ' OFFSET ' . (int) $meta['offset']
)->fetchAll();

$managers = $pdo->query(
    "SELECT u.id, u.email
     FROM users u
     WHERE u.role = 'branch_manager' AND u.status = 'active'
     ORDER BY u.email ASC"
)->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if (!$showNew && $editId > 0) {
    $editSt = $pdo->prepare(
        "SELECT b.*, u.email AS manager_email, u.id AS manager_id
         FROM branches b
         LEFT JOIN users u ON u.id = b.manager_user_id
         WHERE b.id = ?"
    );
    $editSt->execute([$editId]);
    $edit = $editSt->fetch() ?: null;
}
if (!$showNew && !$edit && $branches) {
    $edit = $branches[0];
    $editId = (int) $edit['id'];
} elseif (!$showNew && !$edit && $branchTotal > 0) {
    $first = $pdo->query(
        "SELECT b.*, u.email AS manager_email, u.id AS manager_id
         FROM branches b
         LEFT JOIN users u ON u.id = b.manager_user_id
         ORDER BY b.name ASC LIMIT 1"
    )->fetch();
    if ($first) {
        $edit = $first;
        $editId = (int) $edit['id'];
    }
}

$withManager = (int) $pdo->query(
    'SELECT COUNT(*) FROM branches WHERE manager_user_id IS NOT NULL'
)->fetchColumn();
$activeCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM branches WHERE status = 'active'"
)->fetchColumn();

$pageTitle = 'Branches';
$pageSub = 'Managers · login';
$displayName = $user['email'];
$hidePageHead = true;
$nav = admin_nav('branches');
extract($nav);
require __DIR__ . '/../includes/app_header.php';
?>
        <div class="dg-teach-top">
          <div>
            <h1 class="dg-hello">Branches</h1>
            <p class="admin-page-sub" style="margin:.15rem 0 0">Create branch + manager login</p>
          </div>
          <div class="dg-toolbar">
            <a class="btn btn-primary btn-sm" href="?new=1" style="width:auto">+ New branch</a>
          </div>
        </div>

        <div class="report-strip report-strip-dash" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <div class="report-item">
            <span class="report-label">Total</span>
            <span class="report-value"><?= (int) $branchTotal ?></span>
            <span class="report-hint">Branches</span>
          </div>
          <div class="report-item">
            <span class="report-label">Active</span>
            <span class="report-value"><?= (int) $activeCount ?></span>
            <span class="report-hint">Open for classes</span>
          </div>
          <div class="report-item">
            <span class="report-label">Managers</span>
            <span class="report-value"><?= (int) $withManager ?></span>
            <span class="report-hint">With login</span>
          </div>
        </div>

        <div class="br-layout">
          <section class="dg-card br-list-card">
            <div class="dg-card-head">
              <h3>All branches</h3>
              <span class="muted" style="font-size:.78rem;font-weight:650"><?= (int) $branchTotal ?></span>
            </div>

            <?php if (!$branches): ?>
              <div class="empty-state"><strong>No branches yet</strong>Create one with manager login.</div>
            <?php else: ?>
              <div class="br-list">
                <?php foreach ($branches as $b):
                    $sel = !$showNew && (int) $b['id'] === $editId;
                    $active = ($b['status'] ?? '') === 'active';
                ?>
                  <a class="br-item<?= $sel ? ' is-on' : '' ?>"
                     href="?edit=<?= (int) $b['id'] ?>&page=<?= (int) $meta['page'] ?>">
                    <div class="br-item-main">
                      <strong><?= h($b['name']) ?></strong>
                      <span><?= !empty($b['city']) ? h($b['city']) : '—' ?></span>
                      <em>
                        <?php if (!empty($b['manager_id'])): ?>
                          ID #<?= (int) $b['manager_id'] ?> · <?= h($b['manager_email']) ?>
                        <?php else: ?>
                          No manager login
                        <?php endif; ?>
                      </em>
                    </div>
                    <span class="br-pill<?= $active ? ' is-ok' : '' ?>"><?= $active ? 'Active' : 'Off' ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
              <?= render_pager($meta) ?>
            <?php endif; ?>
          </section>

          <section class="dg-card br-edit-card">
            <?php if ($showNew): ?>
              <div class="dg-card-head">
                <h3>New branch + manager</h3>
                <a class="dg-link" href="/admin/branches.php">Cancel</a>
              </div>
              <form method="post" class="br-form">
                <input type="hidden" name="action" value="create_branch" />

                <p class="br-sec-label">Branch info</p>
                <div class="form-group">
                  <label>Branch name</label>
                  <input type="text" name="name" required placeholder="Agrabad Branch" />
                </div>
                <div class="form-group">
                  <label>City</label>
                  <input type="text" name="city" value="Dhaka" />
                </div>
                <div class="form-group">
                  <label>Address</label>
                  <textarea name="address" rows="2" placeholder="Area, road…"></textarea>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Branch phone</label>
                    <input type="text" name="phone" placeholder="01XXXXXXXXX" />
                  </div>
                  <div class="form-group">
                    <label>Branch email (optional)</label>
                    <input type="email" name="branch_email" placeholder="branch@…" />
                  </div>
                </div>
                <label class="br-check">
                  <input type="checkbox" name="is_outside_dhaka" value="1" />
                  <span class="br-check-text">
                    <strong>Outside Dhaka</strong>
                    <em>Teacher review-এ Transport দেখাবে</em>
                  </span>
                </label>

                <p class="br-sec-label">Manager login (required)</p>
                <p class="muted" style="font-size:.8rem;margin:-.35rem 0 .75rem">
                  এই email + password দিয়ে branch manager login করবে।
                </p>
                <div class="form-group">
                  <label>Manager login email</label>
                  <input type="email" name="manager_email" required placeholder="manager.agrabad@medico.local" />
                </div>
                <div class="form-group">
                  <label>Manager password</label>
                  <input type="text" name="manager_password" required minlength="8" placeholder="Min 8 characters" autocomplete="new-password" />
                </div>

                <button class="btn btn-primary" type="submit" style="width:auto">Create branch &amp; manager</button>
              </form>

            <?php elseif (!$edit): ?>
              <div class="empty-state"><strong>Select a branch</strong>Or create a new one with manager login.</div>
            <?php else: ?>
              <div class="dg-card-head">
                <h3>Edit · <?= h($edit['name']) ?></h3>
              </div>
              <form method="post" class="br-form">
                <input type="hidden" name="action" value="save_branch" />
                <input type="hidden" name="branch_id" value="<?= (int) $edit['id'] ?>" />

                <div class="form-group">
                  <label>Branch name</label>
                  <input type="text" name="name" required value="<?= h($edit['name']) ?>" />
                </div>

                <div class="form-row">
                  <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" placeholder="01XXXXXXXXX" value="<?= h($edit['phone'] ?? '') ?>" />
                  </div>
                  <div class="form-group">
                    <label>Branch email</label>
                    <input type="email" name="email" value="<?= h($edit['email'] ?? '') ?>" />
                  </div>
                </div>

                <div class="form-group">
                  <label>Address</label>
                  <textarea name="address" rows="2"><?= h($edit['address'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                  <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" value="<?= h($edit['city'] ?? 'Dhaka') ?>" />
                  </div>
                  <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                      <option value="active" <?= ($edit['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                      <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                  </div>
                </div>

                <label class="br-check">
                  <input type="checkbox" name="is_outside_dhaka" value="1" <?= !empty($edit['is_outside_dhaka']) ? 'checked' : '' ?> />
                  <span class="br-check-text">
                    <strong>Outside Dhaka</strong>
                    <em>Teacher review-এ Transport দেখাবে</em>
                  </span>
                </label>

                <p class="br-sec-label">Manager login</p>
                <?php if (!empty($edit['manager_id'])): ?>
                  <div class="br-mgr-box">
                    <div><span>User ID</span><strong>#<?= (int) $edit['manager_id'] ?></strong></div>
                    <div><span>Login email</span><strong><?= h($edit['manager_email']) ?></strong></div>
                  </div>
                  <input type="hidden" name="manager_user_id" value="<?= (int) $edit['manager_id'] ?>" />
                  <div class="form-group">
                    <label>Reset password (optional)</label>
                    <input type="text" name="reset_manager_password" minlength="8" placeholder="Leave blank to keep current" autocomplete="new-password" />
                  </div>
                <?php else: ?>
                  <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">No manager yet — create login below or pick existing.</p>
                  <div class="form-group">
                    <label>Existing manager (optional)</label>
                    <select name="manager_user_id">
                      <option value="0">— Create new below —</option>
                      <?php foreach ($managers as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"><?= h($m['email']) ?> · #<?= (int) $m['id'] ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>

                <p class="br-sec-label">Or create new manager login</p>
                <div class="form-group">
                  <label>New manager email</label>
                  <input type="email" name="new_manager_email" placeholder="manager@…" />
                </div>
                <div class="form-group">
                  <label>New manager password</label>
                  <input type="text" name="new_manager_password" minlength="8" placeholder="Min 8 characters" autocomplete="new-password" />
                </div>

                <button class="btn btn-primary" type="submit" style="width:auto;margin-top:.35rem">Save branch</button>
              </form>
            <?php endif; ?>
          </section>
        </div>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
