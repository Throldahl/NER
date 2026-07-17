<?php
declare(strict_types=1);

function captionerner_config_values(): array {
    static $config = null;
    if (is_array($config)) return $config;

    $path = __DIR__ . '/configs/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Missing private configuration file: configs/config.php');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('configs/config.php must return a configuration array.');
    }

    $requiredConstants = [
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'SESSION_NAME',
        'DEFAULT_ASSESSMENT_SLUG',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_AUTH_REQUIRED_DOMAIN',
        'DEFAULT_ADMIN_EMAIL',
    ];
    foreach ($requiredConstants as $constant) {
        if (!defined($constant)) {
            throw new RuntimeException('Missing required configuration constant: ' . $constant);
        }
    }

    $config = $loaded;
    return $config;
}

captionerner_config_values();


function captionerner_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . $charset;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    try {
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (Throwable $e) {
        error_log('captionerner could not set database timezone: ' . $e->getMessage());
    }

    return $pdo;
}
