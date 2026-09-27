<?php
/**
 * Teacher shared helpers
 */

declare(strict_types=1);

function teacher_nav(string $active, string $displayName = 'Teacher'): array
{
    $links = [
        ['id' => 'dashboard', 'href' => '/teacher/dashboard.php', 'icon' => '▣', 'label' => 'Home'],
        ['id' => 'profile', 'href' => '/teacher/profile.php', 'icon' => '◎', 'label' => 'Profile'],
        ['id' => 'notifications', 'href' => '/notifications.php', 'icon' => '◔', 'label' => 'Activity'],
    ];
    return [
        'activeNav' => $active,
        'navRole' => 'Teacher',
        'shellStyle' => 'designo',
        'profileHref' => '/teacher/profile.php',
        'settingsHref' => '/teacher/profile.php',
        'settingsLabel' => 'Profile settings',
        'displayName' => $displayName,
        'navLinks' => $links,
        'mobileNavLinks' => $links,
    ];
}

/** Currently on site? First check-in is kept; multiple in/out allowed. */
function teacher_visit_on_site(array $visit): bool
{
    if (empty($visit['check_in_at'])) {
        return false;
    }
    if (empty($visit['check_out_at'])) {
        return true;
    }
    $lastIn = $visit['last_check_in_at'] ?? $visit['check_in_at'];
    return strtotime((string) $lastIn) > strtotime((string) $visit['check_out_at']);
}

function teacher_owns_class(PDO $pdo, int $classId, int $teacherId): bool
{
    $ok = $pdo->prepare('SELECT id FROM classes WHERE id = ? AND teacher_id = ?');
    $ok->execute([$classId, $teacherId]);
    return (bool) $ok->fetch();
}

/** Get or create branch-day visit row */
function teacher_branch_day(PDO $pdo, int $teacherId, int $branchId, string $day): array
{
    ensure_teacher_flow_schema();
    $st = $pdo->prepare(
        'SELECT * FROM teacher_branch_days WHERE teacher_id = ? AND branch_id = ? AND visit_date = ? LIMIT 1'
    );
    $st->execute([$teacherId, $branchId, $day]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    $pdo->prepare(
        'INSERT INTO teacher_branch_days (teacher_id, branch_id, visit_date) VALUES (?, ?, ?)'
    )->execute([$teacherId, $branchId, $day]);
    $st->execute([$teacherId, $branchId, $day]);
    return $st->fetch() ?: [];
}

function notify_manager_message(PDO $pdo, int $classId, string $teacherName, string $message): void
{
    $message = trim($message);
    if ($message === '') {
        return;
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.branch_id, c.class_date, c.time_slot, c.subject, c.created_by, b.name AS branch_name
         FROM classes c JOIN branches b ON b.id = c.branch_id WHERE c.id = ?'
    );
    $st->execute([$classId]);
    $c = $st->fetch();
    if (!$c) {
        return;
    }
    $slot = format_time($c['time_slot'] ?? null);
    $subject = trim((string) ($c['subject'] ?? '')) ?: 'Class';
    $body = $subject . ' · ' . date('j M', strtotime((string) $c['class_date'])) . ' ' . $slot
        . ' · ' . $c['branch_name'] . "\n\n“" . $message . '”';
    $title = $teacherName . ' → manager note';
    $ids = admin_user_ids($pdo);
    try {
        $mgr = $pdo->prepare(
            "SELECT manager_user_id FROM branches WHERE id = ? AND manager_user_id IS NOT NULL"
        );
        $mgr->execute([(int) $c['branch_id']]);
        $managerId = (int) ($mgr->fetchColumn() ?: 0);
        if ($managerId > 0) {
            $ids[] = $managerId;
        }
    } catch (Throwable $e) {
        // soft-fail
    }
    $creatorId = (int) ($c['created_by'] ?? 0);
    if ($creatorId > 0) {
        $ids[] = $creatorId;
    }
    notify_users($ids, $title, $body, 'teacher_note', 'class', $classId);
}

function notify_branch_visit(PDO $pdo, int $branchId, string $teacherName, string $action, string $day): void
{
    $b = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
    $b->execute([$branchId]);
    $branchName = (string) ($b->fetchColumn() ?: 'Branch');
    if ($action === 'check_in') {
        $title = $teacherName . ' checked in to branch';
        $body = $branchName . ' · ' . date('j M Y', strtotime($day)) . ' · ready for classes';
        $type = 'check_in';
    } else {
        $title = $teacherName . ' checked out of branch';
        $body = $branchName . ' · ' . date('j M Y', strtotime($day)) . ' · left campus';
        $type = 'check_out';
    }

    $ids = admin_user_ids($pdo);
    try {
        $mgr = $pdo->prepare(
            "SELECT manager_user_id FROM branches WHERE id = ? AND manager_user_id IS NOT NULL"
        );
        $mgr->execute([$branchId]);
        $managerId = (int) ($mgr->fetchColumn() ?: 0);
        if ($managerId > 0) {
            $ids[] = $managerId;
        }
        $creators = $pdo->prepare(
            'SELECT DISTINCT created_by FROM classes
             WHERE branch_id = ? AND class_date = ? AND created_by IS NOT NULL'
        );
        $creators->execute([$branchId, $day]);
        foreach ($creators->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $ids[] = (int) $uid;
        }
    } catch (Throwable $e) {
        // soft-fail
    }
    notify_users($ids, $title, $body, $type, 'branch', $branchId);
}
