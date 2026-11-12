<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/mapa-tests-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$processes = [];

function removeDirectory(string $path): void
{
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            removeDirectory($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

register_shutdown_function(function () use (&$processes, $temporary): void {
    foreach (array_reverse($processes) as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    removeDirectory($temporary);
});

function startServer(string $directory, string $router, array $environment): array
{
    global $processes, $temporary;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate test port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $log = $temporary . '/server-' . count($processes) . '.log';
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $directory, $router],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $directory,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP test server.');
    }
    $processes[] = $process;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.05);
        if ($connection !== false) {
            fclose($connection);
            return [$process, 'http://' . $address];
        }
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(20000);
    }
    throw new RuntimeException('PHP test server failed to start: ' . file_get_contents($log));
}

function same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function upstream(string $body, int $status = 200): void
{
    global $temporary;
    file_put_contents($temporary . '/response.json', json_encode(['status' => $status, 'body' => $body], JSON_THROW_ON_ERROR));
}

$passed = 0;
$failed = 0;
function test(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL $name: {$exception->getMessage()}\n");
    }
}

// Use a separate web root with a synthetic .env; the developer's .env is never loaded.
$webRoot = $temporary . '/web';
mkdir($webRoot);
foreach (['bootstrap.php', 'proxy.php', 'index.php', 'router.php', 'map.js', 'map.css', 'favicon.ico'] as $file) {
    copy($root . '/' . $file, $webRoot . '/' . $file);
}
symlink($root . '/vendor', $webRoot . '/vendor');
symlink($root . '/icons', $webRoot . '/icons');
$environment = getenv();
// Keep the test servers single-process, even when the developer uses PHP CLI workers.
unset($environment['PHP_CLI_SERVER_WORKERS']);
foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'] as $name) {
    unset($environment[$name]);
}
$environment['TEST_RESPONSE_FILE'] = $temporary . '/response.json';
[$userdbProcess, $userdbUrl] = startServer(__DIR__ . '/fixtures', __DIR__ . '/fixtures/userdb.php', $environment);
$settings = [
    'USERDB_URL' => $userdbUrl . '/api', // Also verifies normalization of a missing trailing slash.
    'USERDB_USER' => 'test-user',
    'USERDB_API_KEY' => 'test-key',
    'MAPBOX_TOKEN' => 'test-public-token',
];
file_put_contents($webRoot . '/.env', "USERDB_URL=http://127.0.0.1:1/\nUSERDB_USER=wrong\nUSERDB_API_KEY=wrong\nMAPBOX_TOKEN=wrong\n");
[$appProcess, $appUrl] = startServer($webRoot, $webRoot . '/router.php', array_merge($environment, $settings));
$http = new Client(['base_uri' => $appUrl, 'http_errors' => false, 'timeout' => 3, 'proxy' => '']);
$area = [
    'id' => 7,
    'jmeno' => 'INTERNAL-AREA',
    'future_private_field' => 'AREA-SECRET',
    'aps' => [91 => ['id' => 91, 'jmeno' => 'Vysílač <b>&</b>', 'gps' => '50.2,15.8', 'aktivni' => 1, 'ulice_cp' => 'ADDRESS-SECRET', 'mesto' => 'CITY-SECRET', 'psc' => 50000]],
    'admins' => [23 => ['id' => 23, 'nick' => 'Správce <svg>', 'email' => 'EMAIL-SECRET', 'role' => 'ROLE-SECRET']],
];
$expected = [['email' => 'oblast7@hkfree.org', 'aps' => [['jmeno' => 'Vysílač <b>&</b>', 'gps' => '50.2,15.8']], 'admins' => [['nick' => 'Správce <svg>']]]];
$json = fn($value) => json_encode($value, JSON_THROW_ON_ERROR);
$proxyError = function () use ($http): void {
    $response = $http->get('/proxy.php');
    same(502, $response->getStatusCode());
    same(['error' => 'Data mapy se nepodařilo načíst.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
};

test('proxy exposes only public fields, reindexes IDs, preserves text and uses environment authentication', function () use ($http, $area, $json, $expected): void {
    upstream($json([42 => $area]));
    $response = $http->get('/proxy.php');
    same(200, $response->getStatusCode());
    same('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    same($expected, json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
});

test('proxy omits points without GPS and areas without displayed points', function () use ($http, $area, $json, $expected): void {
    $area['aps'][92] = ['jmeno' => 'Hidden', 'gps' => null];
    $area['aps'][93] = ['jmeno' => 'Hidden', 'gps' => ''];
    $area['aps'][94] = ['jmeno' => 'Hidden'];
    upstream($json([$area, ['id' => 8, 'aps' => [['gps' => null]], 'admins' => []], ['id' => 9, 'aps' => [], 'admins' => []]]));
    same($expected, json_decode((string) $http->get('/proxy.php')->getBody(), true, 512, JSON_THROW_ON_ERROR));
});

test('proxy supports empty results and areas without administrators', function () use ($http, $area, $json, $expected): void {
    upstream('[]');
    same('[]', (string) $http->get('/proxy.php')->getBody());
    $area['admins'] = [];
    $expected[0]['admins'] = [];
    upstream($json([$area]));
    same($expected, json_decode((string) $http->get('/proxy.php')->getBody(), true, 512, JSON_THROW_ON_ERROR));
});

foreach (['not-json', 'null', '"SECRET"', '[null]', '[{"id":7,"aps":null,"admins":[]}]'] as $index => $body) {
    test('proxy rejects malformed JSON or structure #' . ($index + 1), function () use ($body, $proxyError): void {
        upstream($body);
        $proxyError();
    });
}
foreach (['id', 'aps', 'admins', 'point', 'gps', 'name', 'admin', 'nick'] as $field) {
    test('proxy rejects invalid ' . $field . ' without returning a partial response', function () use ($area, $field, $json, $proxyError): void {
        $invalid = $area;
        switch ($field) {
            case 'id': $invalid['id'] = '7'; break;
            case 'aps': $invalid['aps'] = 'SECRET'; break;
            case 'admins': $invalid['admins'] = 'SECRET'; break;
            case 'point': $invalid['aps'][91] = 'SECRET'; break;
            case 'gps': $invalid['aps'][91]['gps'] = ['SECRET']; break;
            case 'name': $invalid['aps'][91]['jmeno'] = ['SECRET']; break;
            case 'admin': $invalid['admins'][23] = 'SECRET'; break;
            case 'nick': $invalid['admins'][23]['nick'] = ['SECRET']; break;
        }
        upstream($json([$area, $invalid]));
        $proxyError();
    });
}
foreach ([401, 500, 503] as $status) {
    test('proxy hides upstream HTTP ' . $status . ' details', function () use ($status, $proxyError): void {
        upstream('UPSTREAM-SECRET', $status);
        $proxyError();
    });
}

test('HTML exposes only the public token and safely encodes configuration', function () use ($http): void {
    $response = $http->get('/');
    same(200, $response->getStatusCode());
    $html = (string) $response->getBody();
    same(1, preg_match('/<script id="map-config" type="application\/json">(.*?)<\/script>/s', $html, $matches));
    same(['mapboxToken' => 'test-public-token'], json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR));
    same(false, strpos($html, 'test-key'));
    same(false, strpos($html, 'test-user'));
});

test('development router denies private files and permits the local logo', function () use ($http): void {
    foreach (['/.env', '/%2eenv', '/bootstrap.php', '/vendor/autoload.php', '/tests/fixtures/userdb.php'] as $path) {
        same(404, $http->get($path)->getStatusCode());
    }
    $logo = $http->get('/icons/hkfree-logo-druzstvo.png');
    same(200, $logo->getStatusCode());
    same('image/png', $logo->getHeaderLine('Content-Type'));
});

test('blank configuration produces generic HTTP 500 responses', function () use ($webRoot, $environment, $settings): void {
    [, $url] = startServer($webRoot, $webRoot . '/router.php', array_merge($environment, $settings, ['USERDB_API_KEY' => ' ']));
    $client = new Client(['base_uri' => $url, 'http_errors' => false, 'timeout' => 3, 'proxy' => '']);
    $response = $client->get('/proxy.php');
    same(500, $response->getStatusCode());
    same(['error' => 'Konfigurace mapy není dostupná.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    $response = $client->get('/');
    same(500, $response->getStatusCode());
    same('Konfigurace mapy není dostupná.', (string) $response->getBody());
});

test('HTML cannot be broken out of its JSON script by a configured token', function () use ($webRoot, $environment, $settings): void {
    $token = '</script><img src=x onerror=alert(1)>"&';
    [, $url] = startServer($webRoot, $webRoot . '/router.php', array_merge($environment, $settings, ['MAPBOX_TOKEN' => $token]));
    $html = (string) (new Client(['timeout' => 3, 'proxy' => '']))->get($url)->getBody();
    same(false, strpos($html, $token));
    same(1, preg_match('/<script id="map-config" type="application\/json">(.*?)<\/script>/s', $html, $matches));
    same(['mapboxToken' => $token], json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR));
});

test('configuration can come entirely from .env without process variables', function () use ($webRoot, $environment, $settings, $area, $json, $expected): void {
    $dotenv = '';
    foreach ($settings as $key => $value) {
        unset($environment[$key]);
        $dotenv .= $key . '=' . $value . "\n";
    }
    file_put_contents($webRoot . '/.env', $dotenv);
    [, $url] = startServer($webRoot, $webRoot . '/router.php', $environment);
    upstream($json([$area]));
    $response = (new Client(['timeout' => 3, 'proxy' => '']))->get($url . '/proxy.php');
    same(200, $response->getStatusCode());
    same($expected, json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
});

test('container environment works without a .env file', function () use ($webRoot, $http, $area, $json, $expected): void {
    unlink($webRoot . '/.env');
    upstream($json([$area]));
    $response = $http->get('/proxy.php');
    same(200, $response->getStatusCode());
    same($expected, json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
});

test('missing configuration without .env returns HTTP 500', function () use ($webRoot, $environment, $settings): void {
    unset($environment['USERDB_API_KEY'], $settings['USERDB_API_KEY']);
    [, $url] = startServer($webRoot, $webRoot . '/router.php', array_merge($environment, $settings));
    $response = (new Client(['http_errors' => false, 'timeout' => 3, 'proxy' => '']))->get($url . '/proxy.php');
    same(500, $response->getStatusCode());
    same(['error' => 'Konfigurace mapy není dostupná.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
});

test('unreachable upstream returns generic HTTP 502', function () use ($userdbProcess, $proxyError): void {
    proc_terminate($userdbProcess);
    proc_close($userdbProcess);
    $proxyError();
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
