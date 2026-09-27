<?php
/**
 * Manager — teacher profile (basic first, details on demand)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';
ensure_class_curriculum_schema();

$user = require_login(['branch_manager']);
$pdo = db();
$branch = manager_branch($pdo, $user);
manager_require_setup($branch);

$id = (int) ($_GET['id'] ?? 0);
$showDetails = isset($_GET['details']);

$stmt = $pdo->prepare(
    "SELECT t.*, u.email, u.status AS user_status, u.created_at AS registered_at
     FROM teachers t
     JOIN users u ON u.id = t.user_id
     WHERE t.id = ? AND u.role = 'teacher'
     LIMIT 1"
);
$stmt->execute([$id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    flash('error', 'Teacher not found.');
    redirect('/teacherTreck/manager/teachers.php');
}

$history = $pdo->prepare(
    'SELECT c.id, c.class_date, c.time_slot, c.status, c.subject, c.lecture_no, c.course_category
     FROM classes c
     WHERE c.teacher_id = ? AND c.branch_id = ?
     ORDER BY c.class_date DESC, c.time_slot DESC
     LIMIT 40'
);
$history->execute([(int) $teacher['id'], (int) $branch['id']]);
$rows = $history->fetchAll();

$photo = teacher_photo_src($teacher['avatar_path'] ?? null);
$initials = teacher_initials($teacher['full_name']);
$dobFmt = $teacher['date_of_birth'] ? date('j M Y', strtotime($teacher['date_of_birth'])) : '—';
$regFmt = !empty($teacher['registered_at']) ? date('j M Y', strtotime($teacher['registered_at'])) : '—';

$nav = manager_nav('teachers');
extract($nav);
$pageTitle = $teacher['full_name'];
$pageSub = $showDetails ? 'Full profile' : 'Teacher';
$displayName = $branch['name'];
$topActions = '<a class="btn btn-secondary btn-sm" href="/teacherTreck/manager/teachers.php" style="width:auto">← Teachers</a>'
    . ' <a class="btn btn-primary btn-sm" href="/teacherTreck/manager/classes.php?new=1&teacher_id=' . (int) $teacher['id'] . '" style="width:auto">Assign class</a>';
require __DIR__ . '/../includes/app_header.php';
?>
        <section class="panel profile-sheet">
          <div class="profile-sheet-top">
            <div class="teacher-photo-lg">
              <?php if ($photo): ?>
                <img src="<?= h($photo) ?>" alt="<?= h($teacher['full_name']) ?>" />
              <?php else: ?>
                <span><?= h($initials) ?></span>
              <?php endif; ?>
            </div>
            <div class="profile-sheet-intro">
              <div class="profile-sheet-name">
                <h2><?= h($teacher['full_name']) ?></h2>
                <?= badge($teacher['user_status']) ?>
              </div>
              <p class="profile-sheet-college"><?= h($teacher['medical_college']) ?></p>
              <p class="profile-sheet-email"><?= h($teacher['email']) ?></p>
              <?php if (!$showDetails): ?>
                <div class="profile-basic-actions">
                  <a class="btn btn-primary btn-sm" href="?id=<?= (int) $teacher['id'] ?>&details=1" style="width:auto">View full profile</a>
                  <a class="btn btn-secondary btn-sm" href="tel:<?= h(preg_replace('/\s+/', '', $teacher['phone'])) ?>" style="width:auto"><?= h($teacher['phone']) ?></a>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($showDetails): ?>
            <div class="profile-details-block">
              <div class="list-toolbar" style="border-top:1px solid var(--border);border-bottom:none;padding-bottom:0">
                <div>
                  <h3>About</h3>
                  <span class="list-count">Full contact & personal details</span>
                </div>
                <a class="btn btn-secondary btn-sm" href="?id=<?= (int) $teacher['id'] ?>" style="width:auto">Hide details</a>
              </div>
              <dl class="profile-facts profile-facts-full">
                <div>
                  <dt>Phone</dt>
                  <dd><a href="tel:<?= h(preg_replace('/\s+/', '', $teacher['phone'])) ?>"><?= h($teacher['phone']) ?></a></dd>
                </div>
                <div>
                  <dt>Emergency contact</dt>
                  <dd><?= h($teacher['emergency_contact']) ?></dd>
                </div>
                <div>
                  <dt>Email</dt>
                  <dd><a href="mailto:<?= h($teacher['email']) ?>"><?= h($teacher['email']) ?></a></dd>
                </div>
                <div>
                  <dt>Date of birth</dt>
                  <dd><?= h($dobFmt) ?></dd>
                </div>
                <div>
                  <dt>Medical college</dt>
                  <dd><?= h($teacher['medical_college']) ?></dd>
                </div>
                <div>
                  <dt>Joined</dt>
                  <dd><?= h($regFmt) ?></dd>
                </div>
                <div class="profile-fact-wide">
                  <dt>Address</dt>
                  <dd><?= h($teacher['address']) ?></dd>
                </div>
              </dl>
            </div>
          <?php endif; ?>
        </section>

        <section class="panel panel-tight">
          <div class="list-toolbar">
            <div>
              <h3>Classes at <?= h($branch['name']) ?></h3>
              <span class="list-count"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
            </div>
          </div>
          <div class="table-wrap">
            <table class="data data-compact">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Subject</th>
                  <th>Status</th>
                  <th class="col-actions"></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="4">
                    <div class="empty-state" style="padding:1.5rem 1rem">
                      <strong>No classes at this branch yet</strong>
                    </div>
                  </td>
                </tr>
              <?php else: foreach ($rows as $r): ?>
                <tr>
                  <td class="cell-when">
                    <strong><?= h(format_time($r['time_slot'])) ?></strong>
                    <span><?= h(date('j M Y', strtotime($r['class_date']))) ?></span>
                  </td>
                  <td>
                    <div class="cell-chips">
                      <?php if (!empty($r['subject'])): ?>
                        <span class="chip chip-subject"><?= h($r['subject']) ?></span>
                      <?php endif; ?>
                      <span class="chip"><?= ($r['course_category'] ?? '') === '2nd_timer' ? '2nd' : '1st' ?></span>
                      <?php if (!empty($r['lecture_no'])): ?>
                        <span class="chip chip-lec">L<?= (int) $r['lecture_no'] ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><?= badge($r['status']) ?></td>
                  <td class="col-actions">
                    <a class="btn btn-secondary btn-sm" href="/teacherTreck/manager/class.php?id=<?= (int) $r['id'] ?>">Track</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
