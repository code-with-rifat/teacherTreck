<?php
/**
 * Admin shared helpers
 */

declare(strict_types=1);

function admin_nav(string $active): array
{
    $links = [
        ['id' => 'overview', 'href' => '/teacher-traking/admin/dashboard.php', 'icon' => '▣', 'label' => 'Dashboard'],
        ['id' => 'classes', 'href' => '/teacher-traking/admin/classes.php', 'icon' => '▦', 'label' => 'Classes'],
        ['id' => 'teachers', 'href' => '/teacher-traking/admin/teachers.php', 'icon' => '◎', 'label' => 'Teachers'],
        ['id' => 'reviews', 'href' => '/teacher-traking/admin/reviews.php', 'icon' => '◈', 'label' => 'Reviews'],
        ['id' => 'branches', 'href' => '/teacher-traking/admin/branches.php', 'icon' => '⌂', 'label' => 'Branches'],
        ['id' => 'notifications', 'href' => '/teacher-traking/notifications.php', 'icon' => '◔', 'label' => 'Activity'],
    ];
    return [
        'activeNav' => $active,
        'navRole' => 'Admin',
        'shellStyle' => 'designo',
        'composeHref' => '/teacher-traking/admin/branches.php',
        'composeLabel' => 'Branches',
        'profileHref' => '/teacher-traking/admin/dashboard.php',
        'settingsHref' => '/teacher-traking/admin/dashboard.php',
        'settingsLabel' => 'Account',
        'navLinks' => $links,
        /* Bottom bar: keep 5 clean tabs; Activity stays in bell + sidebar */
        'mobileNavLinks' => array_values(array_filter(
            $links,
            static fn ($l) => ($l['id'] ?? '') !== 'notifications'
        )),
    ];
}

/** @return array{day:string,prev:string,next:string,isToday:bool,dayFull:string} */
function admin_day_nav(?string $raw = null): array
{
    $day = $raw ?? ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = date('Y-m-d');
    }
    return [
        'day' => $day,
        'prev' => date('Y-m-d', strtotime($day . ' -1 day')),
        'next' => date('Y-m-d', strtotime($day . ' +1 day')),
        'isToday' => $day === date('Y-m-d'),
        'dayFull' => date('j M Y', strtotime($day)),
    ];
}
