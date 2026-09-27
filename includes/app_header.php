<?php
/** @var array $user */
/** @var string $pageTitle */
/** @var string $pageSub */
/** @var string $navRole */
/** @var string $activeNav */
/** @var string $displayName */
$flash = take_flash();
$initial = strtoupper(substr($displayName ?? 'M', 0, 1));
$navLinks = $navLinks ?? [];
$profileHref = $profileHref ?? '';
$settingsHref = $settingsHref ?? '';
$settingsLabel = $settingsLabel ?? 'Settings';
$userEmail = $user['email'] ?? '';
$shellStyle = $shellStyle ?? 'default';
$hidePageHead = !empty($hidePageHead);
$isDesigno = $shellStyle === 'designo';
ensure_notifications_schema();
$notifUnread = !empty($user['id']) ? notifications_unread_count((int) $user['id']) : 0;
$firstName = trim(explode(' ', (string) ($displayName ?? 'there'))[0] ?: 'there');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($pageTitle) ?> — MEDICO</title>
  <?= brand_favicon_tags() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Limelight&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/css/app.css" />
  <?php if ($isDesigno): ?>
  <link rel="stylesheet" href="/assets/css/designo.css" />
  <?php endif; ?>
</head>
<body class="<?= $isDesigno ? 'is-designo' : '' ?>">
  <div id="overlay" class="overlay"></div>
  <div class="app-shell<?= $isDesigno ? ' designo-shell' : '' ?>" id="appShell">

  <?php if ($isDesigno): ?>
    <aside class="designo-sidebar" id="designoSidebar">
      <a class="designo-brand" href="<?= h($navLinks[0]['href'] ?? '#') ?>">
        <img class="designo-brand-logo" src="<?= h(brand_logo_url()) ?>" alt="MEDICO" width="36" height="36" />
        <span class="designo-brand-text">MEDICO</span>
      </a>
      <nav class="designo-side-nav">
        <?php foreach ($navLinks as $link): ?>
          <a class="designo-side-link<?= ($activeNav ?? '') === $link['id'] ? ' is-active' : '' ?>"
             href="<?= h($link['href']) ?>">
            <span class="designo-side-ico"><?= h($link['icon'] ?? '•') ?></span>
            <span><?= h($link['label']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!empty($composeHref)): ?>
          <a class="designo-side-link designo-side-cta" href="<?= h($composeHref) ?>">
            <span class="designo-side-ico">＋</span>
            <span><?= h($composeLabel ?? 'Assign class') ?></span>
          </a>
        <?php endif; ?>
      </nav>
      <div class="designo-side-foot">
        <a href="/notifications.php">Notifications<?= $notifUnread ? ' · ' . (int) $notifUnread : '' ?></a>
        <a href="/logout.php" class="is-danger">Sign out</a>
      </div>
    </aside>
  <?php endif; ?>

    <header class="app-navbar<?= $isDesigno ? ' designo-topbar' : '' ?>" id="appNavbar">
      <div class="navbar-left">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Menu" title="Menu">☰</button>
        <?php if (!$isDesigno): ?>
        <a class="navbar-brand" href="<?= h($navLinks[0]['href'] ?? '#') ?>">
          <img class="logo-img" src="<?= h(brand_logo_url()) ?>" alt="MEDICO" width="32" height="32" />
          <span class="brand-name">MEDICO</span>
        </a>
        <nav class="navbar-nav" id="navbarNav">
          <?php foreach ($navLinks as $link): ?>
            <a class="navbar-link <?= ($activeNav ?? '') === $link['id'] ? 'is-active' : '' ?>" href="<?= h($link['href']) ?>">
              <?= h($link['label']) ?>
            </a>
          <?php endforeach; ?>
          <?php if (!empty($composeHref)): ?>
            <a class="navbar-link navbar-link-accent" href="<?= h($composeHref) ?>">
              <?= h($composeLabel ?? 'Assign class') ?>
            </a>
          <?php endif; ?>
        </nav>
        <?php else: ?>
        <nav class="navbar-nav" id="navbarNav" hidden></nav>
        <div class="designo-search-wrap">
          <form class="designo-search" action="#" onsubmit="return false;" role="search">
            <span class="designo-search-ico" aria-hidden="true">⌕</span>
            <?php
            $searchPh = 'Search…';
            $roleForSearch = (string) ($user['role'] ?? $navRole ?? '');
            if ($roleForSearch === 'teacher') {
                $searchPh = 'Search my classes…';
            } elseif ($roleForSearch === 'branch_manager') {
                $searchPh = 'Search teachers & classes…';
            } elseif (in_array($roleForSearch, ['admin', 'super_admin'], true)) {
                $searchPh = 'Search teachers, classes…';
            }
            ?>
            <input type="search" id="globalSearchInline" placeholder="<?= h($searchPh) ?>" autocomplete="off" />
            <kbd>Ctrl K</kbd>
          </form>
          <div id="searchResultsInline" class="designo-search-results" hidden></div>
        </div>
        <?php endif; ?>
      </div>
      <div class="navbar-right">
        <?php if (!$isDesigno): ?>
        <details class="search-menu" id="searchMenu">
          <summary class="nav-icon-btn" aria-label="Search" title="Search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/>
              <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
          </summary>
          <div class="search-panel">
            <input type="search" id="globalSearch" placeholder="Search teachers, classes…" autocomplete="off" />
            <div id="searchResults" class="search-results">
              <div class="search-empty">Type to search teachers & classes</div>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <details class="notif-menu" id="notifMenu">
          <summary class="nav-icon-btn notif-trigger" aria-label="Notifications" title="Notifications">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M6 9a6 6 0 0 1 12 0c0 7 3 7 3 7H3s3 0 3-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M10 18a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            <span class="notif-badge<?= $notifUnread ? ' is-on' : '' ?>" id="notifBadge"><?= $notifUnread > 9 ? '9+' : ($notifUnread ?: '') ?></span>
          </summary>
          <div class="notif-panel">
            <div class="notif-panel-head">
              <strong>Notifications</strong>
              <button type="button" class="linkish" id="markAllRead">Mark all read</button>
            </div>
            <div id="notifList" class="notif-list">
              <div class="search-empty">Loading…</div>
            </div>
            <a class="notif-see-all" href="/notifications.php">See all history</a>
          </div>
        </details>

        <details class="profile-menu">
          <summary class="profile-menu-trigger<?= $isDesigno ? ' designo-profile-trigger' : '' ?>" aria-label="Account menu" title="Account">
            <span class="avatar"><?= h($initial) ?></span>
            <?php if ($isDesigno): ?>
              <span class="designo-profile-name"><?= h($firstName) ?></span>
            <?php endif; ?>
          </summary>
          <div class="profile-menu-panel">
            <div class="profile-menu-head">
              <span class="avatar"><?= h($initial) ?></span>
              <div>
                <strong><?= h($displayName ?? $userEmail) ?></strong>
                <span><?= h($navRole) ?></span>
                <?php if ($userEmail): ?>
                  <em><?= h($userEmail) ?></em>
                <?php endif; ?>
              </div>
            </div>
            <div class="profile-menu-list">
              <?php if ($profileHref): ?>
                <a href="<?= h($profileHref) ?>">Profile details</a>
              <?php endif; ?>
              <?php if ($settingsHref): ?>
                <a href="<?= h($settingsHref) ?>"><?= h($settingsLabel) ?></a>
              <?php endif; ?>
              <a href="/notifications.php">Notification history</a>
              <a href="/logout.php" class="is-danger">Sign out</a>
            </div>
          </div>
        </details>
      </div>
    </header>

    <div class="main<?= $isDesigno ? ' designo-main' : '' ?>">
      <?php if (!$hidePageHead): ?>
      <div class="page-head">
        <div>
          <h1><?= h($pageTitle) ?></h1>
          <?php if (!empty($pageSub)): ?>
            <p><?= h($pageSub) ?></p>
          <?php endif; ?>
        </div>
        <?php if (!empty($topActions)): ?>
          <div class="page-head-actions"><?= $topActions ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="content<?= $isDesigno ? ' designo-content' : '' ?>">
        <?php if ($flash): ?>
          <div class="alert alert-<?= h($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'info' ? 'info' : 'error')) ?> show" style="margin-bottom:1rem">
            <?= h($flash['message']) ?>
          </div>
        <?php endif; ?>
