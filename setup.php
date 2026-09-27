<?php
/**
 * One-click database setup for XAMPP (open in browser once).
 * http://localhost/teacherTreck/setup.php
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$config = require __DIR__ . '/config/app.php';
$db = $config['db'];
$messages = [];
$ok = true;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

ini_set('default_socket_timeout', '5');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], $db['port'], $db['charset']),
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
    $messages[] = ['ok', 'Connected to MySQL.'];

    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $db['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $messages[] = ['ok', 'Database `' . $db['name'] . '` ready.'];
    $pdo->exec('USE `' . str_replace('`', '``', $db['name']) . '`');

    $hasUsers = false;
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        $hasUsers = true;
    } catch (Throwable $e) {
        $hasUsers = false;
    }

    if (!$hasUsers) {
        $schemaFile = __DIR__ . '/database/schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('schema.sql not found');
        }

        $sql = file_get_contents($schemaFile);
        $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql) ?? $sql;
        $sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql) ?? $sql;

        $parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $clean = preg_replace('/^--.*$/m', '', $part) ?? $part;
            $clean = trim($clean);
            if ($clean === '') {
                continue;
            }
            $pdo->exec($clean);
        }
        $messages[] = ['ok', 'Tables imported from schema.sql.'];
    } else {
        $messages[] = ['ok', 'Tables already exist — skipped schema import.'];
    }

    require __DIR__ . '/database/seed_inline.php';
    $messages[] = ['ok', 'Demo users seeded.'];
} catch (Throwable $e) {
    $ok = false;
    $messages[] = ['err', $e->getMessage()];
    $messages[] = ['err', 'XAMPP Control Panel থেকে MySQL Start করো, তারপর এই পেজ Refresh করো.'];
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>MEDICO Setup</title>
  <link rel="icon" href="favicon_io/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png" />
  <link rel="apple-touch-icon" href="favicon_io/apple-touch-icon.png" />
  <link rel="stylesheet" href="assets/css/app.css" />
  <style>
    body { padding: 2rem 1rem; }
    .wrap { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 1.75rem; box-shadow: var(--shadow-md); }
    .log { margin: 1rem 0; font-size: .92rem; }
    .log li { margin: .4rem 0; }
    .ok { color: #047857; }
    .err { color: #b91c1c; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand-mark" style="margin-bottom:1rem">
      <div class="logo">M</div>
      <div>
        <h1 style="font-family:var(--font-display);font-size:1.4rem">MEDICO Setup</h1>
        <span style="color:var(--slate-500);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Database installer</span>
      </div>
    </div>

    <ul class="log">
      <?php foreach ($messages as [$type, $msg]): ?>
        <li class="<?= $type === 'ok' ? 'ok' : 'err' ?>"><?= h($msg) ?></li>
      <?php endforeach; ?>
    </ul>

    <?php if ($ok): ?>
      <p style="margin-bottom:1rem;color:var(--slate-700)">Setup complete. Demo logins:</p>
      <ul style="margin-bottom:1.25rem;font-size:.9rem;color:var(--slate-700)">
        <li>teacher1@medico.local / Teacher@123</li>
        <li>manager.dhanmondi@medico.local / Manager@123</li>
        <li>admin@medico.local / Admin@123</li>
      </ul>
      <a class="btn btn-primary" href="login.php" style="width:auto;display:inline-flex">Go to Login</a>
    <?php else: ?>
      <a class="btn btn-secondary" href="setup.php" style="width:auto;display:inline-flex">Try again</a>
    <?php endif; ?>
  </div>
</body>
</html>
