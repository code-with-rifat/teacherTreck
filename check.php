<?php
/**
 * Deploy check — delete this file after site works.
 * Open: https://YOUR-DOMAIN.com/check.php
 */
header('Content-Type: text/plain; charset=utf-8');
echo "PHP OK " . PHP_VERSION . "\n";
echo "File: " . __FILE__ . "\n";
echo "Dir:  " . __DIR__ . "\n";
echo "index.php: " . (is_file(__DIR__ . '/index.php') ? 'YES' : 'NO') . "\n";
echo "login.php: " . (is_file(__DIR__ . '/teacher-traking/login.php') ? 'YES' : 'NO') . "\n";
echo "config:    " . (is_file(__DIR__ . '/config/app.php') ? 'YES' : 'NO') . "\n";

try {
    $cfg = require __DIR__ . '/config/app.php';
    $db = $cfg['db'];
    echo "base_url: " . ($cfg['base_url'] ?? '(none)') . "\n";
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "DB: OK (" . $db['name'] . ")\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . count($tables) . " (" . implode(', ', array_slice($tables, 0, 8)) . ")\n";
} catch (Throwable $e) {
    echo "DB: FAIL — " . $e->getMessage() . "\n";
}

try {
    require __DIR__ . '/includes/bootstrap.php';
    echo "bootstrap: OK\n";
    ensure_email_auth_schema();
    echo "schema: OK\n";
} catch (Throwable $e) {
    echo "bootstrap FAIL: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
