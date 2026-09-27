<?php
/**
 * Global search JSON — teachers, classes, branches (role-aware)
 */
require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 1) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$pdo = db();
$role = (string) $user['role'];
$like = '%' . $q . '%';
$results = [];

try {
    if (in_array($role, ['branch_manager', 'admin', 'super_admin'], true)) {
        $branchFilter = '';
        $params = [];
        if ($role === 'branch_manager') {
            $b = $pdo->prepare("SELECT id FROM branches WHERE manager_user_id = ? AND status = 'active' LIMIT 1");
            $b->execute([(int) $user['id']]);
            $branchId = (int) ($b->fetchColumn() ?: 0);
            if ($branchId < 1) {
                echo json_encode(['ok' => true, 'results' => []]);
                exit;
            }
            $branchFilter = ' AND c.branch_id = ? ';
        }

        // Teachers
        $tSql = "SELECT t.id, t.full_name, t.phone, t.medical_college
                 FROM teachers t
                 JOIN users u ON u.id = t.user_id
                 WHERE u.status = 'active'
                   AND (t.full_name LIKE ? OR t.phone LIKE ? OR t.medical_college LIKE ? OR u.email LIKE ?)
                 ORDER BY t.full_name ASC
                 LIMIT 8";
        $ts = $pdo->prepare($tSql);
        $ts->execute([$like, $like, $like, $like]);
        foreach ($ts->fetchAll() as $t) {
            $href = $role === 'branch_manager'
                ? '/teacherTreck/manager/teacher.php?id=' . (int) $t['id']
                : '/teacherTreck/admin/dashboard.php';
            $results[] = [
                'group' => 'Teachers',
                'title' => $t['full_name'],
                'subtitle' => trim(($t['phone'] ?? '') . ' · ' . ($t['medical_college'] ?? ''), ' ·'),
                'href' => $href,
            ];
        }

        // Classes
        $cSql = "SELECT c.id, c.class_date, c.time_slot, c.subject, c.lecture_no, c.status, c.course_category,
                        t.full_name AS teacher_name, b.name AS branch_name
                 FROM classes c
                 JOIN teachers t ON t.id = c.teacher_id
                 JOIN branches b ON b.id = c.branch_id
                 WHERE (t.full_name LIKE ? OR c.subject LIKE ? OR b.name LIKE ? OR CAST(c.lecture_no AS CHAR) LIKE ?)
                 {$branchFilter}
                 ORDER BY c.class_date DESC, c.time_slot ASC
                 LIMIT 10";
        $params = [$like, $like, $like, $like];
        if ($role === 'branch_manager') {
            $params[] = $branchId;
        }
        $cs = $pdo->prepare($cSql);
        $cs->execute($params);
        foreach ($cs->fetchAll() as $c) {
            $href = $role === 'branch_manager'
                ? '/teacherTreck/manager/class.php?id=' . (int) $c['id']
                : '/teacherTreck/admin/reviews.php';
            $label = trim(($c['subject'] ?? '') . (!empty($c['lecture_no']) ? ' · L' . (int) $c['lecture_no'] : ''));
            $results[] = [
                'group' => 'Classes',
                'title' => $c['teacher_name'] . ($label ? ' · ' . $label : ''),
                'subtitle' => date('j M Y', strtotime($c['class_date'])) . ' · ' . format_time($c['time_slot']) . ' · ' . $c['branch_name'] . ' · ' . $c['status'],
                'href' => $href,
            ];
        }
    }

    if ($role === 'teacher') {
        $tid = $pdo->prepare('SELECT id FROM teachers WHERE user_id = ?');
        $tid->execute([(int) $user['id']]);
        $teacherId = (int) ($tid->fetchColumn() ?: 0);
        if ($teacherId > 0) {
            $cs = $pdo->prepare(
                "SELECT c.id, c.class_date, c.time_slot, c.subject, c.lecture_no, c.status, b.name AS branch_name,
                        cs.teacher_class_start_at, cs.teacher_class_end_at
                 FROM classes c
                 JOIN branches b ON b.id = c.branch_id
                 LEFT JOIN class_sessions cs ON cs.class_id = c.id
                 WHERE c.teacher_id = ?
                   AND (
                     c.subject LIKE ? OR b.name LIKE ? OR CAST(c.lecture_no AS CHAR) LIKE ?
                     OR c.status LIKE ? OR DATE_FORMAT(c.class_date, '%d %b %Y') LIKE ?
                     OR TIME_FORMAT(c.time_slot, '%h:%i %p') LIKE ?
                   )
                 ORDER BY c.class_date DESC, c.time_slot ASC
                 LIMIT 12"
            );
            $cs->execute([$teacherId, $like, $like, $like, $like, $like, $like]);
            foreach ($cs->fetchAll() as $c) {
                $state = !empty($c['teacher_class_end_at'])
                    ? 'Done'
                    : (!empty($c['teacher_class_start_at']) ? 'Live' : 'Upcoming');
                $results[] = [
                    'group' => 'My classes',
                    'title' => ($c['subject'] ?: 'Class') . (!empty($c['lecture_no']) ? ' · L' . (int) $c['lecture_no'] : '')
                        . ' · ' . format_time($c['time_slot']),
                    'subtitle' => date('j M Y', strtotime($c['class_date'])) . ' · ' . $c['branch_name'] . ' · ' . $state,
                    'href' => '/teacherTreck/teacher/class.php?id=' . (int) $c['id'],
                ];
            }
        }
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'results' => $results]);
