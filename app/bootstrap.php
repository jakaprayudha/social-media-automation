<?php
declare(strict_types=1);

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Navigation.php';
require_once __DIR__ . '/Dashboard.php';

function bootstrap(): App
{
    if (PHP_VERSION_ID < 80300) {
        throw new RuntimeException('PHP 8.3 or newer is required.');
    }
    foreach (['pdo_sqlite', 'mbstring', 'sodium', 'openssl'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Required PHP extension is unavailable: ' . $extension);
        }
    }
    $path = getenv('APP_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
    if (!is_file($path)) {
        throw new RuntimeException('Private configuration is missing. Follow the installation guide.');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid private configuration.');
    }
    return new App($config);
}
