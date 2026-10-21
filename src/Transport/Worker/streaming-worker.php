<?php

declare(strict_types=1);

/**
 * Streaming worker for SSH commands and interactive shells.
 *
 * Modes:
 *   exec  - wrap the command, emit chunk messages, exit on marker.
 *   shell - open a PTY shell and relay stdin/stdout until close.
 *
 * PTY output is emitted with a small tail buffer so the completion marker
 * (__LINOPS_STREAM_xxx:N) is never split across chunk boundaries. If the
 * command blocks waiting for input, the tail is flushed after a short
 * idle window so interactive prompts reach the caller immediately.
 *
 * If the server refuses the PTY shell channel during exec mode and the
 * payload sets allowFallback=true, the command is retried via SSH2::exec()
 * on a fresh connection.
 *
 * Protocol: one JSON object per line on stdin/stdout.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Transport\SshConnector;
use phpseclib3\Net\SSH2;

final class PtyUnavailableException extends \RuntimeException
{
}

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');

if ($stdin === false || $stdout === false) {
    fwrite(STDERR, "Unable to open stdio.\n");
    exit(1);
}

/**
 * @param resource $stdout
 * @param array<string, mixed> $data
 */
function emit($stdout, array $data): void
{
    fwrite($stdout, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fflush($stdout);
}

/**
 * @param array<string, mixed> $payload
 */
function connectSsh(array $payload): SSH2
{
    $config = ServerConfig::fromArray((array) $payload['config']);

    return SshConnector::connect($config);
}

/**
 * Does $str end with any prefix of $marker?
 *
 * Used to avoid flushing the tail when it might be a partially received
 * completion marker. If true, wait for more data.
 */
function endsWithMarkerPrefix(string $str, string $marker): bool
{
    if ($str === '') {
        return false;
    }

    $max = min(strlen($str), strlen($marker));

    for ($i = $max; $i > 0; $i--) {
        if (substr($str, -$i) === substr($marker, 0, $i)) {
            return true;
        }
    }

    return false;
}

/**
 * @param resource $stdout
 * @param resource $stdin
 * @param array<string, mixed> $payload
 */
function handleShell($stdout, $stdin, array $payload): never
{
    try {
        $ssh = connectSsh($payload);
    } catch (\Throwable $e) {
        emit($stdout, ['type' => 'error', 'message' => 'Connection failed: ' . $e->getMessage()]);
        exit(1);
    }

    $ssh->setTerminal('xterm');
    $ssh->setWindowSize(80, 24);
    $ssh->enablePTY();
    $ssh->setTimeout(10.0);

    try {
        $initial = $ssh->read('', SSH2::READ_SIMPLE);
    } catch (\Throwable $e) {
        emit($stdout, ['type' => 'error', 'message' => 'Failed to open shell: ' . $e->getMessage()]);
        exit(1);
    }

    if (is_string($initial) && $initial !== '') {
        emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => $initial]);
    }

    $ssh->setTimeout(0.1);

    $timeout = (float) ($payload['timeout'] ?? 3600.0);
    $startedAt = microtime(true);

    while (true) {
        if (microtime(true) - $startedAt > max(1.0, $timeout)) {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stderr', 'data' => "\n[linops] shell session timed out\n"]);
            break;
        }

        try {
            $chunk = $ssh->read('', SSH2::READ_SIMPLE);
        } catch (\Throwable) {
            break;
        }

        if (is_string($chunk) && $chunk !== '') {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => $chunk]);
        }

        $line = fgets($stdin);
        if ($line !== false) {
            $msg = json_decode(trim($line), true);
            if (is_array($msg)) {
                $mtype = $msg['type'] ?? '';
                if ($mtype === 'input') {
                    $ssh->write((string) ($msg['data'] ?? ''));
                } elseif ($mtype === 'close') {
                    break;
                }
            }
        }

        usleep(20000);
    }

    try {
        $ssh->disconnect();
    } catch (\Throwable) {
    }

    emit($stdout, ['type' => 'exit', 'code' => 0]);
    exit(0);
}

/**
 * @param resource $stdout
 * @param resource $stdin
 */
function streamViaPty(
    SSH2 $ssh,
    $stdout,
    $stdin,
    string $wrappedCommand,
    string $marker,
    float $timeout,
): int {
    $ssh->setTerminal('xterm');
    $ssh->setWindowSize(80, 24);
    $ssh->enablePTY();
    $ssh->setTimeout(10.0);

    try {
        $ssh->write($wrappedCommand . "\n");
    } catch (\Throwable $e) {
        throw new PtyUnavailableException($e->getMessage(), 0, $e);
    }

    $ssh->setTimeout(0.1);

    $startedAt = microtime(true);
    $lastDataAt = $startedAt;
    $pending = '';
    $emitted = 0;
    $tailLen = strlen($marker) + 6;
    $flushAfter = 0.15;
    $prefixStallSince = null;
    $prefixStallLimit = 2.0;

    while (true) {
        if (microtime(true) - $startedAt > max(1.0, $timeout)) {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stderr', 'data' => "\n[linops] timeout reached, closing session\n"]);
            return -1;
        }

        try {
            $chunk = $ssh->read('', SSH2::READ_SIMPLE);
        } catch (\Throwable $e) {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stderr', 'data' => "\n[linops] connection lost: " . $e->getMessage() . "\n"]);
            return -1;
        }

        if (is_string($chunk) && $chunk !== '') {
            $pending .= $chunk;
            $lastDataAt = microtime(true);
        }

        if (preg_match('/^(.*?)' . preg_quote($marker, '/') . ':(-?\d+)/s', $pending, $m) === 1) {
            $markerStart = strlen($m[1]);
            if ($markerStart > $emitted) {
                emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => substr($pending, $emitted, $markerStart - $emitted)]);
            }
            return (int) $m[2];
        }

        $safeLen = max(0, strlen($pending) - $tailLen);
        if ($safeLen > $emitted) {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => substr($pending, $emitted, $safeLen - $emitted)]);
            $emitted = $safeLen;
        }

        // Trim already-emitted bytes so $pending stays bounded.
        if ($emitted > 0) {
            $pending = substr($pending, $emitted);
            $emitted = 0;
        }

        // Idle flush: if output has stalled and the tail is not a partial
        // marker, emit the remaining bytes so interactive prompts reach the
        // caller without waiting for the marker.
        if ($pending !== '' && (microtime(true) - $lastDataAt) >= $flushAfter) {
            $hasPrefix = endsWithMarkerPrefix($pending, $marker);

            if ($hasPrefix) {
                if ($prefixStallSince === null) {
                    $prefixStallSince = microtime(true);
                }
                $forceFlush = (microtime(true) - $prefixStallSince) > $prefixStallLimit;
            } else {
                $prefixStallSince = null;
                $forceFlush = false;
            }

            if (!$hasPrefix || $forceFlush) {
                emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => $pending]);
                $pending = '';
                $prefixStallSince = null;
            }
        }

        $line = fgets($stdin);
        if ($line !== false) {
            $msg = json_decode(trim($line), true);
            if (is_array($msg)) {
                $type = $msg['type'] ?? '';
                if ($type === 'input') {
                    $ssh->write((string) ($msg['data'] ?? ''));
                } elseif ($type === 'close') {
                    return 130;
                }
            }
        }

        usleep(20000);
    }
}

/**
 * @param resource $stdout
 */
function streamViaExec(
    SSH2 $ssh,
    $stdout,
    string $wrappedCommand,
    string $marker,
    float $timeout,
): int {
    $ssh->setTimeout($timeout);
    $exitCode = -1;
    $pending = '';
    $tailLen = strlen($marker) + 6;

    $wrapped = $wrappedCommand . ' 2>&1';

    try {
        $ssh->exec($wrapped, function (string $chunk) use ($stdout, $marker, $tailLen, &$exitCode, &$pending): void {
            if ($chunk === '') {
                return;
            }
            $pending .= $chunk;

            if (preg_match('/^(.*?)' . preg_quote($marker, '/') . ':(-?\d+)/s', $pending, $m) === 1) {
                $exitCode = (int) $m[2];
                if ($m[1] !== '') {
                    emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => $m[1]]);
                }
                $pending = '';
                return;
            }

            if (strlen($pending) > $tailLen) {
                $emitLen = strlen($pending) - $tailLen;
                emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => substr($pending, 0, $emitLen)]);
                $pending = substr($pending, $emitLen);
            }
        });

        if ($pending !== '') {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stdout', 'data' => $pending]);
        }
    } catch (\Throwable $e) {
        emit($stdout, ['type' => 'chunk', 'stream' => 'stderr', 'data' => "\n[linops] exec failed: " . $e->getMessage() . "\n"]);
        return -1;
    }

    return $exitCode;
}

// ─── Wait for the initial payload ──────────────────────────────────────────

stream_set_blocking($stdin, false);

$startPayload = null;
$deadline = microtime(true) + 30.0;
while (microtime(true) < $deadline) {
    $line = fgets($stdin);
    if ($line !== false) {
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded) && in_array($decoded['type'] ?? '', ['exec', 'shell'], true)) {
            $startPayload = $decoded;
            break;
        }
    }
    usleep(20000);
}

if ($startPayload === null) {
    emit($stdout, ['type' => 'error', 'message' => 'No exec/shell payload received within 30s.']);
    exit(1);
}

if (($startPayload['type'] ?? '') === 'shell') {
    handleShell($stdout, $stdin, $startPayload);
}

try {
    $ssh = connectSsh($startPayload);
} catch (\Throwable $e) {
    emit($stdout, ['type' => 'error', 'message' => 'Connection failed: ' . $e->getMessage()]);
    exit(1);
}

$command = (string) ($startPayload['command'] ?? '');
$timeout = (float) ($startPayload['timeout'] ?? 30.0);
$allowFallback = (bool) ($startPayload['allowFallback'] ?? false);
$marker = '__LINOPS_STREAM_' . bin2hex(random_bytes(8)) . '__';

$wrapped = '(' . $command . '); __linops_rc=$?; echo; echo "' . $marker . ':${__linops_rc}"';

try {
    $exitCode = streamViaPty($ssh, $stdout, $stdin, $wrapped, $marker, $timeout);
} catch (PtyUnavailableException $e) {
    if (!$allowFallback) {
        emit($stdout, [
            'type' => 'error',
            'message' => 'PTY shell unavailable and fallback is disabled: ' . $e->getMessage(),
        ]);
        exit(1);
    }

    emit($stdout, [
        'type' => 'chunk',
        'stream' => 'stderr',
        'data' => "\n[linops] WARNING: PTY unavailable on this server ("
            . $e->getMessage()
            . "). Falling back to exec() - interactive input will be ignored.\n",
    ]);

    try {
        $ssh->disconnect();
    } catch (\Throwable) {
    }

    try {
        $ssh = connectSsh($startPayload);
    } catch (\Throwable $e2) {
        emit($stdout, ['type' => 'error', 'message' => 'Reconnect for exec fallback failed: ' . $e2->getMessage()]);
        exit(1);
    }

    $exitCode = streamViaExec($ssh, $stdout, $wrapped, $marker, $timeout);
}

try {
    $ssh->disconnect();
} catch (\Throwable) {
}

emit($stdout, ['type' => 'exit', 'code' => $exitCode]);
exit(0);
