<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

function dockerCommand(array $arguments, bool $check = true): string
{
    $process = proc_open(array_merge(['docker'], $arguments),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start Docker.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($check && $status !== 0) {
        throw new RuntimeException('Docker command failed: ' . $error);
    }
    return trim($output . ($check ? '' : $error));
}

function expectSame($expected, $actual, string $context = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($context === '' ? '' : $context . ': ') .
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$image = getenv('TEST_IMAGE') ?: ($argv[1] ?? '');
if ($image === '') {
    fwrite(STDERR, "Set TEST_IMAGE to a locally built production image.\n");
    exit(1);
}
$name = 'mapa-test-' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/' . $name;
mkdir($temporary, 0700);
copy(__DIR__ . '/fixtures/userdb.php', $temporary . '/userdb.php');
$containers = [];
$networkCreated = false;
$exitCode = 0;
try {
    $respond = function (string $body, int $status = 200) use ($temporary): void {
        file_put_contents($temporary . '/response.json', json_encode(['status' => $status, 'body' => $body], JSON_THROW_ON_ERROR));
    };
    $respond(json_encode([42 => [
        'id' => 7, 'private' => 'AREA-SECRET',
        'aps' => [91 => ['id' => 91, 'jmeno' => 'Vysílač', 'gps' => '50.2,15.8', 'ulice_cp' => 'ADDRESS-SECRET'], 92 => ['gps' => null]],
        'admins' => [23 => ['id' => 23, 'nick' => 'Správce', 'email' => 'EMAIL-SECRET', 'role' => 'ROLE-SECRET']],
    ]], JSON_THROW_ON_ERROR));
    dockerCommand(['network', 'create', $name]);
    $networkCreated = true;
    $containers[] = $name . '-userdb';
    dockerCommand(['run', '-d', '--name', $name . '-userdb', '--network', $name, '--network-alias', 'userdb',
        '--no-healthcheck', '--mount', 'type=bind,source=' . $temporary . ',target=/fixtures,readonly',
        '-e', 'TEST_RESPONSE_FILE=/fixtures/response.json', '--entrypoint', 'php', $image,
        '-S', '0.0.0.0:8080', '/fixtures/userdb.php']);
    $containers[] = $name . '-web';
    dockerCommand(['run', '-d', '--name', $name . '-web', '--network', $name, '-p', '127.0.0.1::80',
        '-e', 'USERDB_URL=http://userdb:8080/api', '-e', 'USERDB_USER=test-user',
        '-e', 'USERDB_API_KEY=test-key', '-e', 'MAPBOX_TOKEN=test-public-token', $image]);
    $address = dockerCommand(['port', $name . '-web', '80/tcp']);
    if (!preg_match('/^127\.0\.0\.1:\d+$/', $address)) {
        throw new RuntimeException('Unexpected published test port.');
    }
    $http = new Client(['base_uri' => 'http://' . $address, 'http_errors' => false, 'timeout' => 3, 'proxy' => '']);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            if ($http->get('/')->getStatusCode() === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable $exception) {
            // Apache may still be starting.
        }
        usleep(100000);
    }
    expectSame(true, $ready);

    $html = (string) $http->get('/')->getBody();
    expectSame(true, strpos($html, '<title>HKFree.org mapa</title>') !== false);
    expectSame(false, strpos($html, 'test-key'));
    expectSame(false, strpos($html, 'test-user'));
    expectSame(1, preg_match('/<script id="map-config" type="application\/json">(.*?)<\/script>/s', $html, $matches));
    expectSame(['mapboxToken' => 'test-public-token'], json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR));
    echo "PASS production HTML and public configuration\n";

    $response = $http->get('/proxy.php');
    expectSame(200, $response->getStatusCode());
    expectSame([['email' => 'oblast7@hkfree.org', 'aps' => [['jmeno' => 'Vysílač', 'gps' => '50.2,15.8']],
        'admins' => [['nick' => 'Správce']]]], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    echo "PASS production PHP, UserDB authentication and public-field filtering\n";

    foreach (['/index.php', '/map.js', '/map.css', '/favicon.ico', '/icons/node_2_1.png', '/icons/hkfree-logo-druzstvo.png'] as $path) {
        $response = $http->get($path);
        expectSame(200, $response->getStatusCode(), $path);
        if (strpos($path, '/icons/') === 0) {
            expectSame(hash_file('sha256', dirname(__DIR__) . $path),
                hash('sha256', (string) $response->getBody()), $path . ' content');
        }
    }
    foreach (['/.env', '/.env.example', '/%2eenv', '/.git/config', '/bootstrap.php', '/vendor/autoload.php',
        '/composer.lock', '/router.php', '/tests/run.php', '/deploy/healthcheck.php', '/proxy.php/extra', '/icons/'] as $path) {
        expectSame(true, in_array($http->get($path)->getStatusCode(), [403, 404], true));
    }
    dockerCommand(['exec', $name . '-web', 'php', '-r',
        'foreach ([".env", ".git", "tests", "deploy", "composer.phar"] as $path) { if (file_exists("/var/www/html/" . $path)) exit(1); }']);
    echo "PASS public assets, denied private paths and clean runtime image\n";

    foreach (['not-json', 'null'] as $body) {
        $respond($body);
        $response = $http->get('/proxy.php');
        expectSame(502, $response->getStatusCode());
        expectSame(['error' => 'Data mapy se nepodařilo načíst.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
    $respond('UPSTREAM-SECRET', 503);
    expectSame(502, $http->get('/proxy.php')->getStatusCode());
    dockerCommand(['exec', $name . '-web', 'php', '/usr/local/lib/mapa/healthcheck.php']);
    echo "PASS upstream errors and health check independent of UserDB\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    foreach ($containers as $container) {
        fwrite(STDERR, dockerCommand(['logs', '--tail', '30', $container], false) . "\n");
    }
    $exitCode = 1;
} finally {
    foreach (array_reverse($containers) as $container) {
        dockerCommand(['rm', '-f', $container], false);
    }
    if ($networkCreated) {
        dockerCommand(['network', 'rm', $name], false);
    }
    foreach (glob($temporary . '/*') as $file) {
        unlink($file);
    }
    rmdir($temporary);
}
exit($exitCode);
