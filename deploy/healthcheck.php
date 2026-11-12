<?php

// Check PHP, application configuration and HTTP without depending on UserDB.
$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1/', false, $context);
$status = $http_response_header[0] ?? '';
exit(is_string($body) && preg_match('~^HTTP/\S+ 200\b~', $status)
    && strpos($body, '<title>HKFree.org mapa</title>') !== false ? 0 : 1);
