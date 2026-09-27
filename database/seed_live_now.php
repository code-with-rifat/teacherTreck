<?php
/**
 * Add a live class starting now for testing
 */
require __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
ensure_class_curriculum_schema();
ensure_review_counts_schema();

$today = date('Y-m-d');
// Round to current hour slot-ish: use HH:00 of now
$slot = date('H:00:00');
$branchId = 1;
$teacherId = 1; // Dr. Ayesha
$managerId = 3;

// Avoid duplicate same teacher+slot today
$find = $pdo->prepare(
    'SELECT id FROM classes WHERE branch_id = ? AND teacher_id = ? AND class_date = ? AND time_slot = ? LIMIT 1'
);
$find->execute([$branchId, $teacherId, $today, $slot]);
$existing = $find->fetch();

if ($existing) {
    $classId = (int) $existing['id'];
    $pdo->prepare(
        "UPDATE classes SET status = 'in_progress', subject = 'Physics', course_category = '1st_timer', lecture_no = 11 WHERE id = ?"
    )->execute([$classId]);
} else {
    $pdo->prepare(
        "INSERT INTO classes (branch_id, teacher_id, class_date, time_slot, course_category, subject, lecture_no, status, created_by)
         VALUES (?, ?, ?, ?, '1st_timer', 'Physics', 11, 'in_progress', ?)"
    )->execute([$branchId, $teacherId, $today, $slot, $managerId]);
    $classId = (int) $pdo->lastInsertId();
}

$session = session_row_for_class($pdo, $classId);
$sid = (int) $session['id'];

// Started ~10 min ago check-in, class start = now
$checkIn = date('Y-m-d H:i:s', time() - 600);
$startAt = date('Y-m-d H:i:s');

$pdo->prepare(
    'UPDATE class_sessions SET
        check_in_at = ?,
        check_in_latitude = 23.7455,
        check_in_longitude = 90.4120,
        check_in_distance_m = 12,
        check_in_allowed_radius_m = 150,
        teacher_class_start_at = ?,
        teacher_times_saved_at = NOW(),
        check_out_at = NULL,
        teacher_class_end_at = NULL,
        student_count_teacher = NULL
     WHERE id = ?'
)->execute([$checkIn, $startAt, $sid]);

// Notify manager
require_once __DIR__ . '/../includes/notifications.php';
notify_teacher_class_action($pdo, $classId, 'class_start', 'Dr. Ayesha Rahman');

echo "OK class_id={$classId} date={$today} slot={$slot} status=in_progress (started now)\n";
echo "Teacher: teacher1@medico.local / Teacher@123\n";
echo "Open: /teacher-traking/teacher/dashboard.php\n";
