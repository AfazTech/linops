<?php

declare(strict_types=1);

/**
 * Streaming worker for SSH commands, interactive shells, and PTY sessions.
 *
 * Modes:
 *   exec  - wrap the command, emit chunk messages, exit on marker.
 *   shell - open a PTY shell and relay stdin/stdout until close.
 *
 * PTY output is emitted with a small tail buffer in exec mode so the
 * completion marker (__LINOPS_STREAM_xxx:N) is never split across chunk
 * boundaries. If the command blocks waiting for input, the tail is flushed
 * after a short idle window so interactive prompts reach the caller
 * immediately.
 *
 * Binary safety: if a chunk contains bytes that are not valid UTF-8 (e.g.
 * `cat /dev/urandom` or a binary editor), json_encode() fails. In that case
 * the chunk is re-emitted as base64 with encoding="base64" and data_b64.
 * The parent (SshStreamingRunner / PtySession) transparently decodes it.
 *
 * If the server refuses the PTY shell channel during exec mode and the
 * payload sets allowFallback=true, the command is retried via SSH2::exec()
 * on a fresh connection.
 *
 * The autoloader path is resolved by walking up from __DIR__ until a
 * vendor/autoload.php is found. This works both for a standalone checkout
 * and when the package is installed as a Composer dependency.
 *
 * Protocol: one JSON object per line on stdin/stdout.
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
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false && isset($data['data']) && is_string($data['data'])) {
        // Fall back to base64 encoding when the payload is not valid UTF-8
        // (e.g. binary PTY output from `cat /dev/urandom`).
        $data['data_b64'] = base64_encode($data['data']);
        unset($data['data']);
        $data['encoding'] = 'base64';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if ($json === false) {
        $json = json_encode(['type' => 'error', 'message' => 'Encoding failure in worker.']);
    }

    if ($json !== false) {
        fwrite($stdout, $json . "\n");
        fflush($stdout);
    }
}

/**
 * Decode an "input" message that may carry plain text or base64-encoded
 * binary data. Returns the raw bytes to write to the SSH channel.
 *
 * @param array<string, mixed> $msg
 */
function decodeInput(array $msg): string
{
    if (($msg['encoding'] ?? '') === 'base64' && isset($msg['data_b64']) && is_string($msg['data_b64'])) {
        $decoded = base64_decode($msg['data_b64'], true);
        return $decoded !== false ? $decoded : '';
    }

    return (string) ($msg['data'] ?? '');
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

    $pty = is_array($payload['pty'] ?? null) ? $payload['pty'] : [];
    $term = (string) ($pty['term'] ?? 'xterm-256color');
    $cols = max(1, (int) ($pty['cols'] ?? 80));
    $rows = max(1, (int) ($pty['rows'] ?? 24));

    $ssh->setTerminal($term);
    $ssh->setWindowSize($cols, $rows);
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

    $timeout = (float) ($payload['timeout'] ?? 0.0);
    $checkTimeout = $timeout > 0;
    $startedAt = microtime(true);
    $exitCode = 0;

    while (true) {
        if ($checkTimeout && microtime(true) - $startedAt > $timeout) {
            emit($stdout, ['type' => 'chunk', 'stream' => 'stderr', 'data' => "\n[linops] shell session timed out\n"]);
            $exitCode = -1;
            break;
        }

        try {
            $chunk = $ssh->read('', SSH2::READ_SIMPLE);
        } catch (\Throwable) {
            $exitCode = -1;
            break;
        }

        if ($chunk === false) {
            // Shell exited naturally (channel closed).
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
                    $data = decodeInput($msg);
                    if ($data !== '') {
                        try {
                            $ssh->write($data);
                        } catch (\Throwable) {
                            $exitCode = -1;
                            break;
                        }
                    }
                } elseif ($mtype === 'resize') {
                    $c = (int) ($msg['cols'] ?? 0);
                    $r = (int) ($msg['rows'] ?? 0);
                    if ($c > 0 && $r > 0) {
                        try {
                            if (method_exists($ssh, 'windowResize')) {
                                $ssh->windowResize($c, $r);
                            } else {
                                $ssh->setWindowSize($c, $r);
                            }
                        } catch (\Throwable) {
                            // Best-effort; phpseclib may lack runtime resize.
                        }
                    }
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

    emit($stdout, ['type' => 'exit', 'code' => $exitCode]);
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

        if ($emitted > 0) {
            $pending = substr($pending, $emitted);
            $emitted = 0;
        }

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
                    $data = decodeInput($msg);
                    if ($data !== '') {
                        $ssh->write($data);
                    }
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
