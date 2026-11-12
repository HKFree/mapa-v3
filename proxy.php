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
    $areas = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($areas)) {
        throw new UnexpectedValueException('Expected a list of areas.');
    }

    // Build a new response explicitly; never forward upstream fields or object keys.
    $publicAreas = [];
    foreach ($areas as $area) {
        if (!is_array($area) || !isset($area['id'], $area['aps'], $area['admins'])
            || !is_int($area['id']) || !is_array($area['aps']) || !is_array($area['admins'])) {
            throw new UnexpectedValueException('Invalid area.');
        }

        $points = [];
        foreach ($area['aps'] as $point) {
            if (!is_array($point)) {
                throw new UnexpectedValueException('Invalid access point.');
            }
            if (empty($point['gps'])) {
                continue;
            }
            if (!is_string($point['gps']) || !isset($point['jmeno']) || !is_string($point['jmeno'])) {
                throw new UnexpectedValueException('Invalid access point fields.');
            }
            $points[] = ['jmeno' => $point['jmeno'], 'gps' => $point['gps']];
        }
        if ($points === []) {
            continue;
        }

        $admins = [];
        foreach ($area['admins'] as $admin) {
            if (!is_array($admin) || !isset($admin['nick']) || !is_string($admin['nick'])) {
                throw new UnexpectedValueException('Invalid administrator.');
            }
            $admins[] = ['nick' => $admin['nick']];
        }

        $publicAreas[] = [
            'email' => 'oblast' . $area['id'] . '@hkfree.org',
            'aps' => $points,
            'admins' => $admins,
        ];
    }

    echo json_encode($publicAreas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (GuzzleException | JsonException | UnexpectedValueException $exception) {
    http_response_code(502);
    echo json_encode(['error' => 'Data mapy se nepodařilo načíst.']);
}
