<?php
/**
 * Teachers module — day roster for this branch (assign from routine)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/manager.php';
ensure_class_curriculum_schema();

$user = require_login(['branch_manager']);
$pdo = db();
$branch = manager_branch($pdo, $user);
manager_require_setup($branch);

$day = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}
$isToday = $day === date('Y-m-d');
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));

$dayStmt = $pdo->prepare(
    'SELECT c.teacher_id, c.id AS class_id, c.time_slot, c.subject, c.lecture_no, c.course_category, c.status,
            t.full_name, t.phone, t.avatar_path
     FROM classes c
     JOIN teachers t ON t.id = c.teacher_id
     WHERE c.branch_id = ? AND c.class_date = ? AND c.status <> \'cancelled\'
     ORDER BY c.time_slot ASC, t.full_name ASC'
);
$dayStmt->execute([(int) $branch['id'], $day]);
$dayClasses = $dayStmt->fetchAll();

$byTeacher = [];
$doneCount = 0;
$liveCount = 0;
foreach ($dayClasses as $row) {
    $tid = (int) $row['teacher_id'];
    if (!isset($byTeacher[$tid])) {
        $byTeacher[$tid] = [
            'id' => $tid,
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'avatar_path' => $row['avatar_path'],
            'slots' => [],
        ];
    }
    $byTeacher[$tid]['slots'][] = $row;
    if ($row['status'] === 'completed') {
        $doneCount++;
    } elseif ($row['status'] === 'in_progress') {
        $liveCount++;
    }
}

$teachingCount = count($byTeacher);
$classCount = count($dayClasses);
$dayLabel = $isToday ? 'Today' : date('D, j M', strtotime($day));

$teacherList = array_values($byTeacher);
$tp = paginate_request(20);
$tMeta = paginate_meta($teachingCount, $tp);
$teacherPage = array_slice($teacherList, $tMeta['offset'], $tMeta['per']);

$nav = manager_nav('teachers');
extract($nav);
$pageTitle = 'Teachers';
$pageSub = $branch['name'];
$displayName = $branch['name'];
$topActions = '<a class="btn btn-primary btn-sm" href="/manager/classes.php?new=1&date=' . h($day) . '" style="width:auto">Assign class</a>';
require __DIR__ . '/../includes/app_header.php';
?>
        <section class="panel panel-tight">
          <div class="list-toolbar">
            <div>
              <h3>Teaching · <?= h($dayLabel) ?></h3>
              <span class="list-count">
                <?= $teachingCount ?> teacher<?= $teachingCount === 1 ? '' : 's' ?>
                · <?= $classCount ?> class<?= $classCount === 1 ? '' : 'es' ?>
                <?php if ($liveCount): ?> · <?= $liveCount ?> live<?php endif; ?>
                <?php if ($doneCount): ?> · <?= $doneCount ?> done<?php endif; ?>
              </span>
            </div>
            <div class="day-nav">
              <a class="btn btn-secondary btn-sm" href="?date=<?= h($prevDay) ?>" aria-label="Previous day">←</a>
              <form method="get" class="day-nav-jump">
                <label class="sr-only" for="jumpDate">Date</label>
                <input id="jumpDate" type="date" name="date" value="<?= h($day) ?>" onchange="this.form.submit()" />
              </form>
              <a class="btn btn-secondary btn-sm" href="?date=<?= h($nextDay) ?>" aria-label="Next day">→</a>
              <?php if (!$isToday): ?>
                <a class="btn btn-secondary btn-sm" href="?date=<?= h(date('Y-m-d')) ?>">Today</a>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$teacherPage): ?>
            <div class="empty-state" style="padding:1.5rem 1rem">
              <strong>এই দিনে কেউ assign নেই</strong>
              Routine থেকে class assign করুন — <?= h(date('j M Y', strtotime($day))) ?>।
            </div>
          <?php else: ?>
            <div class="roster-table">
              <div class="roster-head">
                <span>Teacher</span>
                <span>Classes</span>
                <span>Next slot</span>
                <span></span>
              </div>
              <?php foreach ($teacherPage as $t):
                  $photo = teacher_photo_src($t['avatar_path'] ?? null);
                  $initials = teacher_initials($t['full_name']);
                  $first = $t['slots'][0] ?? null;
                  $slotCount = count($t['slots']);
              ?>
                <details class="roster-row">
                  <summary class="roster-summary">
                    <div class="roster-who">
                      <div class="teacher-photo teacher-photo-sm">
                        <?php if ($photo): ?>
                          <img src="<?= h($photo) ?>" alt="" />
                        <?php else: ?>
                          <span><?= h($initials) ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="cell-class">
                        <strong><?= h($t['full_name']) ?></strong>
                        <span><?= h($t['phone']) ?></span>
                      </div>
                    </div>
                    <div class="roster-col-count"><?= $slotCount ?></div>
                    <div class="roster-col-next">
                      <?php if ($first): ?>
                        <strong><?= h(format_time($first['time_slot'])) ?></strong>
                        <span>
                          <?php if (!empty($first['subject'])): ?><?= h($first['subject']) ?><?php else: ?>Class<?php endif; ?>
                          <?php if ($slotCount > 1): ?> · +<?= $slotCount - 1 ?><?php endif; ?>
                        </span>
                      <?php else: ?>
                        <span>—</span>
                      <?php endif; ?>
                    </div>
                    <span class="roster-chevron" aria-hidden="true"></span>
                  </summary>
                  <div class="roster-detail">
                    <div class="roster-detail-grid">
                      <?php foreach ($t['slots'] as $s): ?>
                        <a class="roster-detail-item" href="/manager/class.php?id=<?= (int) $s['class_id'] ?>">
                          <strong><?= h(format_time($s['time_slot'])) ?></strong>
                          <span>
                            <?php if (!empty($s['subject'])): ?><?= h($s['subject']) ?> · <?php endif; ?>
                            <?= ($s['course_category'] ?? '') === '2nd_timer' ? '2nd' : '1st' ?>
                            <?php if (!empty($s['lecture_no'])): ?> · L<?= (int) $s['lecture_no'] ?><?php endif; ?>
                          </span>
                          <?= badge($s['status']) ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                    <div class="roster-actions">
                      <a class="btn btn-secondary btn-sm" href="/manager/teacher.php?id=<?= (int) $t['id'] ?>">Profile</a>
                      <a class="btn btn-primary btn-sm" href="/manager/classes.php?new=1&teacher_id=<?= (int) $t['id'] ?>&date=<?= h($day) ?>">Assign</a>
                    </div>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
            <?= render_pager($tMeta) ?>
          <?php endif; ?>
        </section>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
