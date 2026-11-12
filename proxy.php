<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/bootstrap.php';
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Konfigurace mapy není dostupná.']);
    exit;
}

try {
    $client = new Client([
        'base_uri' => rtrim($config['USERDB_URL'], '/') . '/',
        'auth' => [$config['USERDB_USER'], $config['USERDB_API_KEY']],
        'connect_timeout' => 5,
        'timeout' => 15,
    ]);

    $response = $client->request('GET', 'areas');
    echo $response->getBody()->getContents();
} catch (GuzzleException $exception) {
    http_response_code(502);
    echo json_encode(['error' => 'Data mapy se nepodařilo načíst.']);
}
