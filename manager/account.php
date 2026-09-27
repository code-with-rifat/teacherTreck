<?php
/**
 * Manager — account / branch profile (read-only; Admin edits)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';

$user = require_login(['branch_manager']);
ensure_branch_contact_schema();
$pdo = db();
$branch = manager_branch($pdo, $user);
manager_require_setup($branch);

$globalRadius = (int) $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'global_checkin_radius_m'"
)->fetchColumn();
$radius = (int) ($branch['geofence_radius_m'] ?? 0);
if ($radius < 1) {
    $radius = $globalRadius;
}

$nav = manager_nav('account');
extract($nav);
$pageTitle = 'My account';
$pageSub = 'Branch profile · view only';
$displayName = $branch['name'];
require __DIR__ . '/../includes/app_header.php';
?>
        <section class="panel account-card">
          <div class="panel-header">
            <h3>Branch profile</h3>
            <span class="muted">View only · Admin edits</span>
          </div>
          <div class="panel-body">
            <div class="account-hero">
              <div class="avatar account-avatar"><?= h(strtoupper(substr($branch['name'], 0, 1))) ?></div>
              <div>
                <strong class="account-name"><?= h($branch['name']) ?></strong>
                <p class="muted"><?= h($navRole) ?> · <?= !empty($branch['setup_completed']) ? 'Ready' : 'Setup pending' ?></p>
              </div>
            </div>

            <div class="account-grid">
              <div class="account-field">
                <span class="field-label">Branch name</span>
                <strong><?= h($branch['name']) ?></strong>
              </div>
              <div class="account-field">
                <span class="field-label">Branch code</span>
                <strong><?= h($branch['code'] ?? '—') ?></strong>
              </div>
              <div class="account-field">
                <span class="field-label">Branch phone</span>
                <strong><?= h($branch['phone'] ?: '—') ?></strong>
              </div>
              <div class="account-field">
                <span class="field-label">Branch email</span>
                <strong><?= h($branch['email'] ?: '—') ?></strong>
              </div>
              <div class="account-field">
                <span class="field-label">Login email</span>
                <strong><?= h($user['email']) ?></strong>
              </div>
              <div class="account-field">
                <span class="field-label">City</span>
                <strong><?= h($branch['city'] ?? '—') ?></strong>
              </div>
              <div class="account-field account-field-wide">
                <span class="field-label">Address</span>
                <strong><?= h($branch['address'] ?? '—') ?></strong>
              </div>
              <div class="account-field">
                <span class="field-label">Check-in radius</span>
                <strong><?= $radius ?> m</strong>
              </div>
              <div class="account-field">
                <span class="field-label">GPS pin</span>
                <strong>
                  <?php if (!empty($branch['latitude']) && !empty($branch['longitude'])): ?>
                    <?= h((string) $branch['latitude']) ?>, <?= h((string) $branch['longitude']) ?>
                  <?php else: ?>
                    Not set
                  <?php endif; ?>
                </strong>
              </div>
            </div>

            <p class="account-note muted">
              Branch phone, email, name, map location ও radius শুধু <strong>Admin</strong> set করে।
              এখানে শুধু দেখা যায় — teachers Admin-এর pin + radius অনুযায়ী check-in করে।
            </p>

            <div class="roster-actions">
              <a class="btn btn-primary btn-sm" href="/teacherTreck/manager/dashboard.php" style="width:auto">Dashboard</a>
            </div>
          </div>
        </section>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
