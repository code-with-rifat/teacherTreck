<?php
/**
 * Demo fill — many teachers + multi-slot classes (scheduled / live / completed)
 * Run: C:\xampp\php82\php.exe database/seed_demo_fill.php
 *        or open via browser if needed
 */

declare(strict_types=1);

$config = require __DIR__ . '/../config/app.php';
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'],
    $config['db']['name'],
    $config['db']['charset']
);

$pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Ensure curriculum columns exist
$cols = $pdo->query('SHOW COLUMNS FROM classes')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('subject', $cols, true)) {
    $pdo->exec("ALTER TABLE classes ADD COLUMN subject VARCHAR(120) NULL AFTER course_category");
}
if (!in_array('lecture_no', $cols, true)) {
    $pdo->exec('ALTER TABLE classes ADD COLUMN lecture_no SMALLINT UNSIGNED NULL AFTER subject');
}
$sessionCols = $pdo->query('SHOW COLUMNS FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'count_best' => 'INT UNSIGNED NULL',
    'count_good' => 'INT UNSIGNED NULL',
    'count_bad' => 'INT UNSIGNED NULL',
    'count_repeat' => 'INT UNSIGNED NULL',
    'teacher_class_start_at' => 'DATETIME NULL',
    'teacher_class_end_at' => 'DATETIME NULL',
    'teacher_times_saved_at' => 'DATETIME NULL',
    'manager_class_start_at' => 'DATETIME NULL',
    'manager_class_end_at' => 'DATETIME NULL',
    'manager_times_saved_at' => 'DATETIME NULL',
    'manager_review_saved_at' => 'DATETIME NULL',
] as $col => $def) {
    if (!in_array($col, $sessionCols, true)) {
        $pdo->exec("ALTER TABLE class_sessions ADD COLUMN {$col} {$def}");
    }
}

$branchId = (int) $pdo->query("SELECT id FROM branches WHERE code = 'DHK-SHAN' LIMIT 1")->fetchColumn();
$managerId = (int) $pdo->query("SELECT id FROM users WHERE email = 'manager.dhanmondi@medico.local' LIMIT 1")->fetchColumn();
if (!$branchId || !$managerId) {
    fwrite(STDERR, "Branch/manager missing. Run seed.php first.\n");
    exit(1);
}

$teachers = [
    ['teacher1@medico.local', 'Dr. Ayesha Rahman', '01700000001', 'Dhaka Medical College', 'Gulshan, Dhaka', '1995-03-15'],
    ['teacher2@medico.local', 'Dr. Karim Hasan', '01700000002', 'Sir Salimullah Medical College', 'Mirpur, Dhaka', '1994-07-22'],
    ['teacher3@medico.local', 'Dr. Nusrat Jahan', '01700000003', 'Chittagong Medical College', 'Banani, Dhaka', '1996-01-10'],
    ['teacher4@medico.local', 'Dr. Rafiq Islam', '01700000004', 'Rajshahi Medical College', 'Uttara, Dhaka', '1993-11-05'],
    ['teacher5@medico.local', 'Dr. Farhana Akter', '01700000005', 'Sylhet MAG Osmani Medical', 'Dhanmondi, Dhaka', '1997-05-18'],
    ['teacher6@medico.local', 'Dr. Imran Hossain', '01700000006', 'Mymensingh Medical College', 'Mohammadpur, Dhaka', '1992-09-30'],
    ['teacher7@medico.local', 'Dr. Sabina Yasmin', '01700000007', 'Shaheed Suhrawardy Medical', 'Lalmatia, Dhaka', '1995-12-12'],
    ['teacher8@medico.local', 'Dr. Tanvir Ahmed', '01700000008', 'Dhaka Medical College', 'Bashundhara, Dhaka', '1994-04-08'],
    ['teacher9@medico.local', 'Dr. Mehnaz Chowdhury', '01700000009', 'Sir Salimullah Medical College', 'Tejgaon, Dhaka', '1996-08-25'],
    ['teacher10@medico.local', 'Dr. Shakib Al Hasan', '01700000010', 'Chittagong Medical College', 'Rampura, Dhaka', '1991-02-14'],
];

$pass = password_hash('Teacher@123', PASSWORD_BCRYPT);
$teacherIds = [];

foreach ($teachers as $i => [$email, $name, $phone, $college, $address, $dob]) {
    $ex = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $ex->execute([$email]);
    $u = $ex->fetch();
    if ($u) {
        $userId = (int) $u['id'];
        $pdo->prepare("UPDATE users SET password_hash = ?, status = 'active', role = 'teacher' WHERE id = ?")
            ->execute([$pass, $userId]);
    } else {
        $pdo->prepare("INSERT INTO users (email, password_hash, role, status) VALUES (?, ?, 'teacher', 'active')")
            ->execute([$email, $pass]);
        $userId = (int) $pdo->lastInsertId();
    }

    $tEx = $pdo->prepare('SELECT id FROM teachers WHERE user_id = ?');
    $tEx->execute([$userId]);
    $t = $tEx->fetch();
    if ($t) {
        $tid = (int) $t['id'];
        $pdo->prepare(
            'UPDATE teachers SET full_name = ?, phone = ?, emergency_contact = ?, date_of_birth = ?, medical_college = ?, address = ? WHERE id = ?'
        )->execute([$name, $phone, '018' . substr($phone, -8), $dob, $college, $address, $tid]);
    } else {
        $pdo->prepare(
            'INSERT INTO teachers (user_id, full_name, phone, emergency_contact, date_of_birth, medical_college, address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $name, $phone, '018' . substr($phone, -8), $dob, $college, $address]);
        $tid = (int) $pdo->lastInsertId();
    }
    $teacherIds[] = $tid;
    echo "Teacher ready: {$name}\n";
}

// Ensure subject/lecture columns usable
$subjects = ['Physics', 'Chemistry', 'Biology', 'English', 'General Knowledge', 'Higher Math', 'ICT', 'Bangla'];
$slots = ['07:00:00', '10:00:00', '13:00:00', '16:00:00'];
$today = date('Y-m-d');

// Clear today's demo classes for clean fill (optional: only seed-created)
// Keep existing but skip duplicates by teacher+slot

$plan = [
    // morning 7 — completed
    [0, '07:00:00', 'Physics', 3, '1st_timer', 'completed'],
    [1, '07:00:00', 'Chemistry', 2, '2nd_timer', 'completed'],
    // 10 AM — mix
    [0, '10:00:00', 'Biology', 5, '1st_timer', 'completed'],
    [2, '10:00:00', 'Physics', 4, '1st_timer', 'in_progress'],
    [3, '10:00:00', 'English', 1, '2nd_timer', 'scheduled'],
    [4, '10:00:00', 'Biology', 6, '1st_timer', 'scheduled'],
    // 1 PM
    [5, '13:00:00', 'Chemistry', 7, '1st_timer', 'in_progress'],
    [6, '13:00:00', 'Higher Math', 2, '2nd_timer', 'scheduled'],
    [7, '13:00:00', 'ICT', 3, '1st_timer', 'scheduled'],
    [1, '13:00:00', 'General Knowledge', 4, '1st_timer', 'completed'],
    // 4 PM
    [8, '16:00:00', 'Bangla', 1, '2nd_timer', 'scheduled'],
    [9, '16:00:00', 'Physics', 8, '1st_timer', 'scheduled'],
    [2, '16:00:00', 'Chemistry', 5, '1st_timer', 'scheduled'],
    [4, '16:00:00', 'Biology', 9, '2nd_timer', 'completed'],
];

$find = $pdo->prepare(
    'SELECT id FROM classes WHERE branch_id = ? AND teacher_id = ? AND class_date = ? AND time_slot = ? LIMIT 1'
);
$insClass = $pdo->prepare(
    'INSERT INTO classes (branch_id, teacher_id, class_date, time_slot, course_category, subject, lecture_no, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$updClass = $pdo->prepare(
    'UPDATE classes SET course_category = ?, subject = ?, lecture_no = ?, status = ? WHERE id = ?'
);
$findSession = $pdo->prepare('SELECT id FROM class_sessions WHERE class_id = ?');
$insSession = $pdo->prepare('INSERT INTO class_sessions (class_id) VALUES (?)');

$created = 0;
foreach ($plan as [$ti, $slot, $subject, $lec, $cat, $status]) {
    $tid = $teacherIds[$ti];
    $find->execute([$branchId, $tid, $today, $slot]);
    $row = $find->fetch();
    if ($row) {
        $classId = (int) $row['id'];
        $updClass->execute([$cat, $subject, $lec, $status, $classId]);
    } else {
        $insClass->execute([$branchId, $tid, $today, $slot, $cat, $subject, $lec, $status, $managerId]);
        $classId = (int) $pdo->lastInsertId();
        $created++;
    }

    $findSession->execute([$classId]);
    $sess = $findSession->fetch();
    if ($sess) {
        $sid = (int) $sess['id'];
    } else {
        $insSession->execute([$classId]);
        $sid = (int) $pdo->lastInsertId();
    }

    // Fill session by status
    if ($status === 'completed') {
        $pdo->prepare(
            'UPDATE class_sessions SET
                check_in_at = CONCAT(?, \' 06:45:00\'),
                check_out_at = CONCAT(?, \' \', ADDTIME(?, \'02:00:00\')),
                teacher_class_start_at = CONCAT(?, \' \', ?),
                teacher_class_end_at = CONCAT(?, \' \', ADDTIME(?, \'01:45:00\')),
                teacher_times_saved_at = NOW(),
                manager_class_start_at = CONCAT(?, \' \', ?),
                manager_class_end_at = CONCAT(?, \' \', ADDTIME(?, \'01:50:00\')),
                manager_times_saved_at = NOW(),
                student_count_teacher = ?,
                student_count_manager = ?,
                count_best = ?, count_good = ?, count_bad = ?, count_repeat = ?,
                manager_review_saved_at = NOW(),
                verified_at = NOW(),
                verified_by = ?
             WHERE id = ?'
        )->execute([
            $today, $today, $slot,
            $today, $slot,
            $today, $slot,
            $today, $slot,
            $today, $slot,
            38 + $ti, 40 + $ti,
            8 + $ti, 20, 5, 2,
            $managerId, $sid,
        ]);
    } elseif ($status === 'in_progress') {
        $pdo->prepare(
            'UPDATE class_sessions SET
                check_in_at = CONCAT(?, \' \', SUBTIME(?, \'00:15:00\')),
                teacher_class_start_at = CONCAT(?, \' \', ?),
                teacher_times_saved_at = NOW(),
                check_out_at = NULL,
                teacher_class_end_at = NULL,
                manager_class_start_at = NULL,
                manager_class_end_at = NULL,
                count_best = NULL, count_good = NULL, count_bad = NULL, count_repeat = NULL,
                manager_review_saved_at = NULL
             WHERE id = ?'
        )->execute([$today, $slot, $today, $slot, $sid]);
    } else {
        // scheduled — clear session activity
        $pdo->prepare(
            'UPDATE class_sessions SET
                check_in_at = NULL, check_out_at = NULL,
                teacher_class_start_at = NULL, teacher_class_end_at = NULL,
                manager_class_start_at = NULL, manager_class_end_at = NULL,
                count_best = NULL, count_good = NULL, count_bad = NULL, count_repeat = NULL,
                manager_review_saved_at = NULL, student_count_teacher = NULL, student_count_manager = NULL
             WHERE id = ?'
        )->execute([$sid]);
    }
}

echo "\nDone. New classes created: {$created}\n";
echo "Today ({$today}) at Shanti Nager — open manager dashboard & teachers page.\n";
echo "All demo teachers password: Teacher@123\n";
echo "Emails: teacher1@medico.local … teacher10@medico.local\n";
