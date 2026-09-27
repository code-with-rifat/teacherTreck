<?php
/**
 * Branch Manager shared helpers
 */

declare(strict_types=1);

function manager_branch(PDO $pdo, array $user): array
{
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE manager_user_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([(int) $user['id']]);
    $branch = $stmt->fetch();
    if (!$branch) {
        flash('error', 'No branch assigned to this manager.');
        redirect('/teacher-traking/logout.php');
    }
    return $branch;
}

/** Soft check — Admin sets location; do not block manager UI. */
function manager_require_setup(array $branch, string $current = ''): void
{
}

function manager_nav(string $active): array
{
    $links = [
        ['id' => 'dashboard', 'href' => '/teacher-traking/manager/dashboard.php', 'icon' => '▣', 'label' => 'Home'],
        ['id' => 'classes', 'href' => '/teacher-traking/manager/classes.php', 'icon' => '▦', 'label' => 'Classes'],
        ['id' => 'teachers', 'href' => '/teacher-traking/manager/teachers.php', 'icon' => '◎', 'label' => 'Teachers'],
        ['id' => 'notifications', 'href' => '/teacher-traking/notifications.php', 'icon' => '◔', 'label' => 'Activity'],
        ['id' => 'account', 'href' => '/teacher-traking/manager/account.php', 'icon' => '◌', 'label' => 'Account'],
    ];
    return [
        'activeNav' => $active,
        'navRole' => 'Branch Manager',
        'shellStyle' => 'designo',
        'composeHref' => '/teacher-traking/manager/classes.php?new=1',
        'composeLabel' => 'Assign class',
        'profileHref' => '/teacher-traking/manager/account.php',
        'settingsHref' => '/teacher-traking/manager/account.php',
        'settingsLabel' => 'Account',
        'navLinks' => $links,
        'mobileNavLinks' => $links,
    ];
}
