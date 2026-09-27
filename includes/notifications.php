<?php
/**
 * Notifications + search helpers
 */

declare(strict_types=1);

function ensure_notifications_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              title VARCHAR(191) NOT NULL,
              body TEXT NOT NULL,
              type VARCHAR(50) NOT NULL DEFAULT 'info',
              related_type VARCHAR(50) NULL,
              related_id BIGINT UNSIGNED NULL,
              is_read TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_notifications_user_read (user_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore if missing privileges; app still runs
    }
}

function notify_user(
    int $userId,
    string $title,
    string $body,
    string $type = 'info',
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    ensure_notifications_schema();
    if ($userId < 1) {
        return;
    }
    try {
        db()->prepare(
            'INSERT INTO notifications (user_id, title, body, type, related_type, related_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $title, $body, $type, $relatedType, $relatedId]);
    } catch (Throwable $e) {
        // soft-fail
    }
}

/** @param list<int> $userIds */
function notify_users(
    array $userIds,
    string $title,
    string $body,
    string $type = 'info',
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    $seen = [];
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid < 1 || isset($seen[$uid])) {
            continue;
        }
        $seen[$uid] = true;
        notify_user($uid, $title, $body, $type, $relatedType, $relatedId);
    }
}

/** All active Admin + Super Admin user IDs. */
function admin_user_ids(PDO $pdo): array
{
    try {
        return array_map(
            'intval',
            $pdo->query(
                "SELECT id FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** Notify every active Admin + Super Admin. */
function notify_admins(
    string $title,
    string $body,
    string $type = 'info',
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    ensure_notifications_schema();
    notify_users(admin_user_ids(db()), $title, $body, $type, $relatedType, $relatedId);
}

/**
 * Notify branch manager + all Admins + Super Admins (deduped).
 */
function notify_branch_managers(
    int $branchId,
    string $title,
    string $body,
    string $type = 'teacher_action',
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    ensure_notifications_schema();
    $pdo = db();
    $ids = admin_user_ids($pdo);
    try {
        $mgr = $pdo->prepare(
            "SELECT manager_user_id FROM branches WHERE id = ? AND manager_user_id IS NOT NULL AND status = 'active'"
        );
        $mgr->execute([$branchId]);
        $managerId = (int) ($mgr->fetchColumn() ?: 0);
        if ($managerId > 0) {
            $ids[] = $managerId;
        }
    } catch (Throwable $e) {
        // soft-fail
    }
    notify_users($ids, $title, $body, $type, $relatedType, $relatedId);
}

function notify_teacher_class_action(PDO $pdo, int $classId, string $actionKey, string $teacherName): void
{
    $st = $pdo->prepare(
        'SELECT c.id, c.branch_id, c.class_date, c.time_slot, c.subject, c.lecture_no, c.course_category,
                c.created_by, b.name AS branch_name
         FROM classes c
         JOIN branches b ON b.id = c.branch_id
         WHERE c.id = ?'
    );
    $st->execute([$classId]);
    $c = $st->fetch();
    if (!$c) {
        return;
    }

    $slot = format_time($c['time_slot'] ?? null);
    $subject = trim((string) ($c['subject'] ?? '')) ?: 'Class';
    $lec = !empty($c['lecture_no']) ? ' · L' . (int) $c['lecture_no'] : '';
    $when = date('j M Y', strtotime((string) $c['class_date'])) . ' · ' . $slot;

    $map = [
        'check_in' => [
            'title' => $teacherName . ' checked in',
            'body' => $subject . $lec . ' at ' . $c['branch_name'] . ' · ' . $when,
            'type' => 'check_in',
        ],
        'class_start' => [
            'title' => $teacherName . ' started class',
            'body' => $subject . $lec . ' · ' . $when,
            'type' => 'class_start',
        ],
        'class_end' => [
            'title' => $teacherName . ' ended class',
            'body' => $subject . $lec . ' · ' . $when,
            'type' => 'class_end',
        ],
        'review' => [
            'title' => $teacherName . ' submitted branch review',
            'body' => $subject . $lec . ' · ' . $when . ' · ' . $c['branch_name'],
            'type' => 'review',
        ],
        'note' => [
            'title' => $teacherName . ' sent a note',
            'body' => $subject . $lec . ' · ' . $when,
            'type' => 'teacher_note',
        ],
    ];

    if (!isset($map[$actionKey])) {
        return;
    }
    $m = $map[$actionKey];
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

    notify_users($ids, $m['title'], $m['body'], $m['type'], 'class', $classId);
}

/** Manager saved times / review — ping Admin + Super Admin (+ teacher). */
function notify_manager_class_action(
    PDO $pdo,
    int $classId,
    string $actionKey,
    string $branchName
): void {
    $st = $pdo->prepare(
        'SELECT c.id, c.class_date, c.time_slot, c.subject, c.lecture_no, c.teacher_id,
                t.full_name AS teacher_name, t.user_id AS teacher_user_id
         FROM classes c
         JOIN teachers t ON t.id = c.teacher_id
         WHERE c.id = ?'
    );
    $st->execute([$classId]);
    $c = $st->fetch();
    if (!$c) {
        return;
    }

    $subject = trim((string) ($c['subject'] ?? '')) ?: 'Class';
    $lec = !empty($c['lecture_no']) ? ' · L' . (int) $c['lecture_no'] : '';
    $when = date('j M Y', strtotime((string) $c['class_date'])) . ' · ' . format_time($c['time_slot'] ?? null);
    $teacher = (string) ($c['teacher_name'] ?? 'Teacher');

    if ($actionKey === 'times') {
        $title = 'Manager saved class times';
        $body = $teacher . ' · ' . $subject . $lec . ' · ' . $when . ' · ' . $branchName;
        $type = 'manager_times';
    } elseif ($actionKey === 'review') {
        $title = 'Manager submitted class review';
        $body = $teacher . ' · ' . $subject . $lec . ' · ' . $when . ' · ' . $branchName;
        $type = 'manager_review';
    } else {
        return;
    }

    $ids = admin_user_ids($pdo);
    $teacherUid = (int) ($c['teacher_user_id'] ?? 0);
    if ($teacherUid > 0) {
        $ids[] = $teacherUid;
    }
    notify_users($ids, $title, $body, $type, 'class', $classId);
}

function notifications_unread_count(int $userId): int
{
    ensure_notifications_schema();
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function notifications_recent(int $userId, int $limit = 12): array
{
    ensure_notifications_schema();
    try {
        $st = db()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function notification_link(array $n, string $role): string
{
    $type = (string) ($n['related_type'] ?? '');
    $id = (int) ($n['related_id'] ?? 0);
    if ($type === 'class' && $id > 0) {
        if ($role === 'branch_manager') {
            return '/teacherTreck/manager/class.php?id=' . $id;
        }
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return '/teacherTreck/admin/review.php?class_id=' . $id;
        }
        return '/teacherTreck/teacher/class.php?id=' . $id;
    }
    if ($type === 'branch' && $id > 0 && in_array($role, ['admin', 'super_admin'], true)) {
        return '/teacherTreck/admin/branches.php?edit=' . $id;
    }
    if ($type === 'user' && $id > 0 && $role === 'super_admin') {
        return '/teacherTreck/super/dashboard.php?tab=users&q=' . $id;
    }
    return '/teacherTreck/notifications.php';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return (int) floor($diff / 86400) . 'd ago';
    }
    return date('j M Y · h:i A', $ts);
}
