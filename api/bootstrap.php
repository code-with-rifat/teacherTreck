<?php
/**
 * MEDICO CMS — Bootstrap
 */

declare(strict_types=1);

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone']);

spl_autoload_register(function (string $class): void {
    $prefix = 'Medico\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if ($config['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

return $config;
