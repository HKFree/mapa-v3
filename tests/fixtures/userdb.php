<?php

// Local replacement for UserDB; never contacts an external service.
if (getenv('TEST_RESPONSE_FILE') === false) {
    http_response_code(404);
    exit;
}

if ($_SERVER['REQUEST_URI'] !== '/api/areas'
    || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('test-user:test-key')) {
    http_response_code(403);
    exit;
}

$response = json_decode(file_get_contents(getenv('TEST_RESPONSE_FILE')), true, 512, JSON_THROW_ON_ERROR);
http_response_code($response['status']);
header('Content-Type: application/json');
echo $response['body'];
