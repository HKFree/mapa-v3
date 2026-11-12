<?php

// Router for the PHP development server: only expose public map files.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$publicFiles = ['/', '/index.php', '/proxy.php', '/map.js', '/map.css', '/favicon.ico', '/icons/node_2_1.png'];

if (!in_array($path, $publicFiles, true)) {
    http_response_code(404);
    exit;
}

return false;
