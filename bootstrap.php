<?php

require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

// Container environment variables take precedence over the local .env file.
$config = [];
foreach (['USERDB_URL', 'USERDB_USER', 'USERDB_API_KEY', 'MAPBOX_TOKEN'] as $name) {
    $value = getenv($name);
    if ($value === false) {
        $value = $_ENV[$name] ?? null;
    }
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Missing configuration: ' . $name);
    }
    $config[$name] = $value;
}

return $config;
