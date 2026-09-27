<?php
/** @var array|null $flash */
/** @var string $pageTitle */
/** @var string $brandSub */
$pageTitle = $pageTitle ?? 'MEDICO';
$brandSub = $brandSub ?? 'Class & Teacher Management';
$flash = $flash ?? take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($pageTitle) ?></title>
  <?= brand_favicon_tags() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Limelight&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/teacher-traking/assets/css/app.css" />
</head>
<body class="auth-body">
  <div class="auth-page">
    <aside class="auth-visual">
      <div class="brand-mark">
        <span class="brand-logo-wrap">
          <img class="logo-img" src="<?= h(brand_logo_url()) ?>" alt="MEDICO" width="72" height="72" />
        </span>
        <div>
          <h1 class="brand-wordmark">MEDICO</h1>
          <span><?= h($brandSub) ?></span>
        </div>
      </div>
      <div class="auth-visual-copy">
        <p class="auth-visual-line"><?= h($heroTitle ?? 'Classes. Teachers. Presence.') ?></p>
        <?php if (!empty($heroText)): ?>
          <p class="auth-visual-support"><?= h($heroText) ?></p>
        <?php endif; ?>
      </div>
    </aside>
    <main class="auth-panel">
      <div class="auth-card<?= !empty($wideCard) ? ' auth-card--wide' : '' ?>">
        <?php if ($flash): ?>
          <div class="alert alert-<?= h($flash['type'] === 'success' ? 'success' : 'error') ?> show">
            <?= h($flash['message']) ?>
          </div>
        <?php endif; ?>
