<?php
/**
 * Deploy check — delete after site works.
 * Open: https://classes.web.medico.com.bd/check.php
 */
header('Content-Type: text/plain; charset=utf-8');
echo "PHP OK " . PHP_VERSION . "\n";
echo "File: " . __FILE__ . "\n";
echo "Dir:  " . __DIR__ . "\n";
echo "index.php: " . (is_file(__DIR__ . '/index.php') ? 'YES' : 'NO') . "\n";
echo "login.php: " . (is_file(__DIR__ . '/login.php') ? 'YES' : 'NO') . "\n";
echo "config:    " . (is_file(__DIR__ . '/config/app.php') ? 'YES' : 'NO') . "\n";

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    echo "\nERROR: Need PHP 7.4+\n";
    exit;
}
echo "PHP version: compatible with 7.4+\n";

try {
    $cfg = require __DIR__ . '/config/app.php';
    $db = $cfg['db'];
    echo "base_url: '" . ($cfg['base_url'] ?? '') . "'\n";
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "DB: OK (" . $db['name'] . ")\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . count($tables) . "\n";
    if (count($tables) < 5) {
        echo "Import database/schema_cpanel.sql in phpMyAdmin\n";
    }
} catch (Throwable $e) {
    echo "DB: FAIL — " . $e->getMessage() . "\n";
}

try {
    require __DIR__ . '/includes/bootstrap.php';
    echo "bootstrap: OK\n";
} catch (Throwable $e) {
    echo "bootstrap FAIL: " . $e->getMessage() . "\n";
}
