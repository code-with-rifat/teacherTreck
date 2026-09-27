<?php
/**
 * Notifications JSON — list / mark read
 */
require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

ensure_notifications_schema();
$pdo = db();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    } else {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
    }
    echo json_encode(['ok' => true, 'unread' => notifications_unread_count($uid)]);
    exit;
}

$limit = min(50, max(5, (int) ($_GET['limit'] ?? 12)));
$rows = notifications_recent($uid, $limit);
$role = (string) $user['role'];
$out = [];
foreach ($rows as $n) {
    $out[] = [
        'id' => (int) $n['id'],
        'title' => $n['title'],
        'body' => $n['body'],
        'type' => $n['type'],
        'is_read' => (int) $n['is_read'] === 1,
        'created_at' => $n['created_at'],
        'when' => time_ago($n['created_at']),
        'date_label' => date('j M Y · h:i A', strtotime($n['created_at'])),
        'href' => notification_link($n, $role),
    ];
}

echo json_encode([
    'ok' => true,
    'unread' => notifications_unread_count($uid),
    'items' => $out,
]);
