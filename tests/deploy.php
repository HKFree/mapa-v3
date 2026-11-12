<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function command(array $arguments, string $cwd, ?array $environment = null, bool $check = true): array
{
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start test command.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($check && $status !== 0) {
        throw new RuntimeException('Test command failed: ' . $error);
    }
    return ['status' => $status, 'output' => $output, 'error' => $error];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $directory): void
{
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            removeTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function fixture(string $directory): array
{
    mkdir($directory);
    $source = $directory . '/source';
    $live = $directory . '/live';
    $bin = $directory . '/bin';
    mkdir($source);
    mkdir($bin);
    command(['git', 'init', '--bare', '-b', 'master', $directory . '/remote.git'], $directory);
    command(['git', 'init', '-b', 'master'], $source);
    command(['git', 'config', 'user.name', 'Deploy test'], $source);
    command(['git', 'config', 'user.email', 'deploy@example.test'], $source);
    file_put_contents($source . '/.gitignore', ".env\n");
    file_put_contents($source . '/version', 'old');
    command(['git', 'add', '.'], $source);
    command(['git', 'commit', '-qm', 'old'], $source);
    $old = trim(command(['git', 'rev-parse', 'HEAD'], $source)['output']);
    file_put_contents($source . '/version', 'new');
    command(['git', 'commit', '-qam', 'new'], $source);
    $target = trim(command(['git', 'rev-parse', 'HEAD'], $source)['output']);
    command(['git', 'remote', 'add', 'origin', $directory . '/remote.git'], $source);
    command(['git', 'push', 'origin', 'master'], $source);
    command(['git', 'clone', $directory . '/remote.git', $live], $directory);
    command(['git', 'checkout', '-b', 'server-master', $old], $live);
    $dotenv = "# keep formatting\r\nUSERDB_API_KEY='local-test-only'\r\nWEB_IMAGE=ghcr.io/example/mapa:latest\r\n";
    file_put_contents($live . '/.env', $dotenv);
    copy(__DIR__ . '/fixtures/docker', $bin . '/docker');
    chmod($bin . '/docker', 0700);
    $environment = array_merge(getenv(), ['PATH' => $bin . ':' . getenv('PATH'),
        'MOCK_DOCKER_LOG' => $directory . '/docker.log', 'MOCK_IMAGE_REVISION' => $target, 'MOCK_FAIL' => '']);
    return compact('directory', 'live', 'old', 'target', 'dotenv', 'environment');
}

function deploy(array $fixture, array $environment = [], ?string $revision = null): array
{
    return command(['bash', dirname(__DIR__) . '/deploy/deploy.sh', $revision ?? $fixture['target'], $fixture['live']],
        $fixture['directory'], array_merge($fixture['environment'], $environment), false);
}

function head(array $fixture): string
{
    return trim(command(['git', 'rev-parse', 'HEAD'], $fixture['live'])['output']);
}

function operations(array $fixture): array
{
    $file = $fixture['directory'] . '/docker.log';
    return is_file($file) ? array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($file, FILE_IGNORE_NEW_LINES)) : [];
}

function notActivated(array $fixture): void
{
    foreach (operations($fixture) as $args) {
        check(($args[1] ?? '') !== 'up', 'Existing container must not be replaced.');
    }
    check(head($fixture) === $fixture['old'], 'Previous checkout must be preserved.');
}

$tests = [
    'deploys master, preserves .env, checks image revision and HTTP without building' => function ($f): void {
        $result = deploy($f);
        check($result['status'] === 0, $result['error']);
        check(head($f) === $f['target'], 'Target commit was not checked out.');
        check(file_get_contents($f['live'] . '/.env') === $f['dotenv'], '.env changed.');
        check(strpos($result['output'] . $result['error'], 'local-test-only') === false, 'Secret printed.');
        $operations = operations($f);
        $pull = $up = null;
        foreach ($operations as $index => $args) {
            check(!in_array('build', $args, true) && !in_array('down', $args, true), 'Unexpected destructive/build command.');
            if (($args[1] ?? '') === 'pull') { $pull = $index; }
            if (($args[1] ?? '') === 'up') { $up = $index; check(in_array('--wait', $args, true), 'Deployment must wait for health.'); }
        }
        check($pull !== null && $up > $pull, 'Pull must precede activation.');
        check(strpos($result['output'], 'HTTP check passed') !== false, 'Missing health result.');
    },
    'refuses tracked local changes' => function ($f): void {
        file_put_contents($f['live'] . '/version', 'Manual change');
        check(deploy($f)['status'] !== 0, 'Dirty checkout accepted.');
        check(file_get_contents($f['live'] . '/version') === 'Manual change', 'Local change overwritten.');
        notActivated($f);
    },
    'refuses untracked files' => function ($f): void {
        file_put_contents($f['live'] . '/manual', 'Keep');
        check(deploy($f)['status'] !== 0, 'Untracked file accepted.');
        notActivated($f);
    },
    'requires server .env' => function ($f): void {
        unlink($f['live'] . '/.env');
        check(deploy($f)['status'] !== 0, 'Missing .env accepted.');
        notActivated($f);
    },
    'skips older CI runs after master advances' => function ($f): void {
        $result = deploy($f, [], $f['old']);
        check($result['status'] === 0 && strpos($result['output'], 'Skipping') !== false, 'Old run was not skipped.');
        notActivated($f);
    },
    'accepts manual invocation without a SHA' => function ($f): void {
        check(deploy($f, [], '')['status'] === 0 && head($f) === $f['target'], 'Manual deploy failed.');
    },
    'refuses an image built from another commit' => function ($f): void {
        check(deploy($f, ['MOCK_IMAGE_REVISION' => $f['old']])['status'] !== 0, 'Wrong image accepted.');
        notActivated($f);
    },
    'serializes manual deployments with a lock' => function ($f): void {
        $lock = fopen($f['live'] . '/.git/mapa-deploy.lock', 'w');
        flock($lock, LOCK_EX);
        try { check(deploy($f)['status'] !== 0, 'Concurrent deploy accepted.'); }
        finally { fclose($lock); }
        notActivated($f);
    },
];
foreach (['config', 'pull', 'run'] as $operation) {
    $tests['failed ' . $operation . ' restores the previous branch before activation'] = function ($f) use ($operation): void {
        check(deploy($f, ['MOCK_FAIL' => $operation])['status'] !== 0, 'Failure ignored.');
        notActivated($f);
        check(trim(command(['git', 'symbolic-ref', '--short', 'HEAD'], $f['live'])['output']) === 'server-master', 'Previous branch not restored.');
        check(file_get_contents($f['live'] . '/.env') === $f['dotenv'], '.env changed.');
    };
}
foreach (['up', 'exec'] as $operation) {
    $tests['failed ' . $operation . ' reports failure without claiming rollback'] = function ($f) use ($operation): void {
        $result = deploy($f, ['MOCK_FAIL' => $operation]);
        check($result['status'] !== 0, 'Failure ignored.');
        check(head($f) === $f['target'], 'Checkout should match attempted deployment.');
        check(strpos($result['error'], 'No automatic rollback') !== false, 'Missing failure guidance.');
    };
}

$temporary = sys_get_temp_dir() . '/mapa-deploy-tests-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test(fixture($temporary . '/' . count(glob($temporary . '/*'))));
            echo "PASS $name\n";
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, "FAIL $name: {$exception->getMessage()}\n");
        }
    }
} finally {
    removeTree($temporary);
}
printf("\n%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
