<?php
/**
 * Shared seed logic — expects $pdo connected to medico_cms
 */

declare(strict_types=1);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO connection required for seeding');
}

$users = [
    ['superadmin@medico.local', 'SuperAdmin@123', 'super_admin'],
    ['admin@medico.local', 'Admin@123', 'admin'],
    ['manager.dhanmondi@medico.local', 'Manager@123', 'branch_manager'],
    ['teacher1@medico.local', 'Teacher@123', 'teacher'],
];

$pdo->beginTransaction();

foreach ($users as [$email, $pass, $role]) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare('UPDATE users SET password_hash = ?, role = ?, status = ? WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_BCRYPT), $role, 'active', $existing['id']]);
        $userId = (int) $existing['id'];
    } else {
        $pdo->prepare('INSERT INTO users (email, password_hash, role, status) VALUES (?, ?, ?, ?)')
            ->execute([$email, password_hash($pass, PASSWORD_BCRYPT), $role, 'active']);
        $userId = (int) $pdo->lastInsertId();
    }

    if ($role === 'teacher') {
        $check = $pdo->prepare('SELECT id FROM teachers WHERE user_id = ?');
        $check->execute([$userId]);
        if (!$check->fetch()) {
            $pdo->prepare(
                'INSERT INTO teachers (user_id, full_name, phone, emergency_contact, date_of_birth, medical_college, address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                'Dr. Ayesha Rahman',
                '01700000001',
                '01700000099',
                '1995-03-15',
                'Dhaka Medical College',
                'Gulshan, Dhaka',
            ]);
        }
    }

    if ($role === 'branch_manager') {
        $check = $pdo->prepare('SELECT id FROM branches WHERE code = ?');
        $check->execute(['DHK-SHAN']);
        if (!$check->fetch()) {
            $pdo->prepare(
                'INSERT INTO branches (name, code, address, city, is_outside_dhaka, latitude, longitude, geofence_radius_m, manager_user_id, setup_completed)
                 VALUES (?, ?, ?, ?, 0, ?, ?, 150, ?, 1)'
            )->execute([
                'Shanti Nager Branch',
                'DHK-SHAN',
                'Shantinagar, Dhaka',
                'Dhaka',
                23.7385000,
                90.4142000,
                $userId,
            ]);
        } else {
            $pdo->prepare('UPDATE branches SET manager_user_id = ?, name = ?, address = ? WHERE code = ?')
                ->execute([$userId, 'Shanti Nager Branch', 'Shantinagar, Dhaka', 'DHK-SHAN']);
        }
    }
}

$chk = $pdo->query("SELECT id FROM branches WHERE code = 'CTG-AGR'")->fetch();
if (!$chk) {
    $pdo->exec(
        "INSERT INTO branches (name, code, address, city, is_outside_dhaka, latitude, longitude, setup_completed, status)
         VALUES ('Agrabad Branch', 'CTG-AGR', 'Agrabad C/A, Chattogram', 'Chattogram', 1, 22.3265000, 91.8110000, 0, 'active')"
    );
}

$teacherId = (int) $pdo->query("SELECT id FROM teachers WHERE full_name = 'Dr. Ayesha Rahman' LIMIT 1")->fetchColumn();
$branchId = (int) $pdo->query("SELECT id FROM branches WHERE code = 'DHK-SHAN' LIMIT 1")->fetchColumn();
if (!$branchId) {
    $branchId = (int) $pdo->query("SELECT id FROM branches WHERE code = 'DHK-DHAN' LIMIT 1")->fetchColumn();
}
$managerId = (int) $pdo->query("SELECT id FROM users WHERE email = 'manager.dhanmondi@medico.local' LIMIT 1")->fetchColumn();

if ($teacherId && $branchId && $managerId) {
    $exists = $pdo->prepare(
        'SELECT id FROM classes WHERE branch_id = ? AND teacher_id = ? AND class_date = CURDATE() AND time_slot = ?'
    );
    $exists->execute([$branchId, $teacherId, '10:00:00']);
    if (!$exists->fetch()) {
        $pdo->prepare(
            'INSERT INTO classes (branch_id, teacher_id, class_date, time_slot, course_category, created_by)
             VALUES (?, ?, CURDATE(), ?, ?, ?)'
        )->execute([$branchId, $teacherId, '10:00:00', '1st_timer', $managerId]);
    }
}

$pdo->commit();
