<?php
/**
 * Manager — view branch location set by Admin (read-only)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';

$user = require_login(['branch_manager']);
$pdo = db();
$branch = manager_branch($pdo, $user);

$globalRadius = (int) $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'global_checkin_radius_m'"
)->fetchColumn();
$radius = (int) ($branch['geofence_radius_m'] ?? 0);
if ($radius < 1) {
    $radius = max(20, $globalRadius ?: 150);
}
$mapsKey = google_maps_api_key();
$hasPin = !empty($branch['latitude']) && !empty($branch['longitude']);
$lat = $hasPin ? (float) $branch['latitude'] : 23.8103;
$lng = $hasPin ? (float) $branch['longitude'] : 90.4125;

$nav = manager_nav('location');
extract($nav);
$pageTitle = 'Branch location';
$pageSub = 'Set by Admin · view only';
$displayName = $branch['name'];
require __DIR__ . '/../includes/app_header.php';
?>
        <?php if (!$hasPin): ?>
          <div class="alert alert-info show" style="margin-bottom:1rem">
            Admin এখনো এই branch-এর Google Map location সেট করেনি। Location set হলে teachers check-in করতে পারবে।
          </div>
        <?php endif; ?>

        <section class="panel location-panel gmap-card">
          <div class="panel-header">
            <h3><?= h($branch['name']) ?></h3>
            <span class="muted">Admin pin · <?= (int) $radius ?> m radius</span>
          </div>
          <div class="panel-body">
            <?php if ($hasPin && $mapsKey !== ''): ?>
              <div class="gmap-wrap">
                <div id="branchMapView" class="branch-map"></div>
                <div class="gmap-status">View only · teachers check in inside this circle</div>
              </div>
              <p class="muted" style="font-size:.82rem;margin:.85rem 0 0">
                Pin: <?= h((string) $branch['latitude']) ?>, <?= h((string) $branch['longitude']) ?>
                <?php if (!empty($branch['address'])): ?> · <?= h($branch['address']) ?><?php endif; ?>
              </p>
            <?php elseif ($hasPin): ?>
              <p><strong>Pin:</strong> <?= h((string) $branch['latitude']) ?>, <?= h((string) $branch['longitude']) ?></p>
              <p class="muted">Radius: <?= (int) $radius ?> m</p>
            <?php else: ?>
              <div class="empty-state">
                <strong>No location yet</strong>
                Admin → Branches থেকে Google Map-এ search করে pin add করবে।
              </div>
            <?php endif; ?>

            <div class="roster-actions" style="margin-top:1rem">
              <a class="btn btn-secondary btn-sm" href="/teacher-traking/manager/account.php" style="width:auto">Account</a>
              <a class="btn btn-primary btn-sm" href="/teacher-traking/manager/dashboard.php" style="width:auto">Dashboard</a>
            </div>
          </div>
        </section>

<?php if ($hasPin && $mapsKey !== ''): ?>
<script>
function initManagerMapView() {
  const el = document.getElementById('branchMapView');
  if (!el || !window.google || !google.maps) return;
  const lat = <?= json_encode($lat) ?>;
  const lng = <?= json_encode($lng) ?>;
  const radius = <?= (int) $radius ?>;
  const map = new google.maps.Map(el, {
    center: { lat, lng },
    zoom: 17,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: false,
    draggable: true,
    gestureHandling: 'cooperative'
  });
  new google.maps.Marker({ map, position: { lat, lng } });
  const circle = new google.maps.Circle({
    map,
    center: { lat, lng },
    radius,
    strokeColor: '#1a73e8',
    strokeWeight: 2,
    fillColor: '#1a73e8',
    fillOpacity: 0.14,
    clickable: false
  });
  map.fitBounds(circle.getBounds(), 36);
}
</script>
<script async defer
  src="https://maps.googleapis.com/maps/api/js?key=<?= h($mapsKey) ?>&callback=initManagerMapView">
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
