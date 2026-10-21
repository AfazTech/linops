<?php

declare(strict_types=1);

/**
 * Isolated worker process.
 *
 * Reads JSON lines from STDIN, executes SSH commands and SFTP operations
 * with the blocking phpseclib transport, and writes JSON lines to STDOUT.
 *
 * The autoloader path is resolved by walking up from __DIR__ until a
 * vendor/autoload.php is found. This works both for a standalone checkout
 * (src/Transport/Worker/worker.php) and when the package is installed as a
 * Composer dependency (vendor/<vendor>/<package>/src/Transport/Worker/...).
 */

$findAutoload = static function (string $startDir): ?string {
    $dir = $startDir;
    for ($i = 0; $i < 12; $i++) {
        $candidate = $dir . '/vendor/autoload.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
    return null;
};

$autoload = getenv('LINOPS_AUTOLOAD') ?: '';
if ($autoload === '' || !is_file($autoload)) {
    $autoload = $findAutoload(__DIR__);
}
if ($autoload === null) {
    fwrite(STDERR, "LinOps worker: unable to locate vendor/autoload.php.\n");
    exit(1);
}
require $autoload;

use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Transport\PhpseclibTransport;

$transport = new PhpseclibTransport();
$connectedKey = null;

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function handle(array $payload, PhpseclibTransport $transport, ?string &$connectedKey): array
{
    $type = (string) ($payload['type'] ?? '');

    if ($type === 'close') {
        $transport->disconnect();
        $connectedKey = null;
        return ['ok' => true, 'closing' => true];
    }

    if ($type === 'ping') {
        return ['ok' => true, 'pong' => true];
    }

    if (!in_array($type, ['execute', 'sftp_upload', 'sftp_download', 'sftp_read'], true)) {
        return ['ok' => false, 'error' => 'Unknown request type: ' . $type];
    }

    $config = ServerConfig::fromArray((array) $payload['config']);
    $key = $config->getConnectionKey();

    if ($connectedKey !== $key || !$transport->isConnected()) {
        $transport->connect($config);
        $connectedKey = $key;
    }

    if ($type === 'execute') {
        $commands = array_values(array_map('strval', (array) ($payload['commands'] ?? [])));
        $timeout = isset($payload['timeout']) ? (float) $payload['timeout'] : null;
        $results = $transport->executeCommands($commands, $timeout);

        return [
            'ok' => true,
            'results' => array_map(static fn ($r) => $r->toArray(), $results),
        ];
    }

    if ($type === 'sftp_upload') {
        $result = $transport->upload(
            (string) $payload['localPath'],
            (string) $payload['remotePath'],
        );

        return [
            'ok' => $result->success,
            'bytes' => $result->bytesTransferred,
            'error' => $result->error,
        ];
    }

    if ($type === 'sftp_download') {
        $result = $transport->download(
            (string) $payload['remotePath'],
            (string) $payload['localPath'],
        );

        return [
            'ok' => $result->success,
            'bytes' => $result->bytesTransferred,
            'error' => $result->error,
        ];
    }

    // sftp_read
    $content = $transport->readFile(
        (string) $payload['remotePath'],
        (int) $payload['maxBytes'],
    );

    return [
        'ok' => true,
        'data' => base64_encode($content),
        'size' => strlen($content),
    ];
}

$stdin = fopen('php://stdin', 'r');
if ($stdin === false) {
    fwrite(STDERR, "Unable to open stdin.\n");
    exit(1);
}

while (($line = fgets($stdin)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $payload = json_decode($line, true);
    if (!is_array($payload)) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'Invalid JSON']) . "\n");
        fflush(STDOUT);
        continue;
    }

    try {
        $response = handle($payload, $transport, $connectedKey);
    } catch (\Throwable $e) {
        $response = [
            'ok' => false,
            'errorType' => $e::class,
            'error' => $e->getMessage(),
        ];
    }

    fwrite(STDOUT, json_encode($response) . "\n");
    fflush(STDOUT);

    if (($response['closing'] ?? false) === true) {
        break;
    }
}

$transport->disconnect();
exit(0);
