<?php
/**
 * Deploy check — delete this file after site works.
 * Open: https://YOUR-DOMAIN.com/check.php
 */
header('Content-Type: text/plain; charset=utf-8');
echo "PHP OK\n";
echo "File: " . __FILE__ . "\n";
echo "Dir:  " . __DIR__ . "\n";
echo "index.php: " . (is_file(__DIR__ . '/index.php') ? 'YES' : 'NO') . "\n";
echo "login.php: " . (is_file(__DIR__ . '/login.php') ? 'YES' : 'NO') . "\n";
echo "config:    " . (is_file(__DIR__ . '/config/app.php') ? 'YES' : 'NO') . "\n";

try {
    $cfg = require __DIR__ . '/config/app.php';
    $db = $cfg['db'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "DB: OK (" . $db['name'] . ")\n";
} catch (Throwable $e) {
    echo "DB: FAIL — " . $e->getMessage() . "\n";
}
