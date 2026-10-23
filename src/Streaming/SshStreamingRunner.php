<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use AfazTech\LinOps\Exception\WorkerException;
use AfazTech\LinOps\ServerConfig;
use Amp\Cancellation;
use Amp\Process\Process;
use function Amp\async;
use function Amp\delay;

/**
 * Streams a remote command or shell over SSH by spawning a dedicated worker
 * process that uses phpseclib in PTY mode.
 *
 * Also implements PtySessionRunnerInterface to open a live interactive PTY
 * session as a duplex Amp stream (see PtySession).
 *
 * Protocol (parent <-> worker), one JSON object per line:
 *   parent -> worker: {"type":"exec","config":{...},"command":"...","timeout":N,"allowFallback":bool}
 *   parent -> worker: {"type":"shell","config":{...},"timeout":N,"pty":{...}}
 *   parent -> worker: {"type":"input","data":"..."}                     (plain text)
 *   parent -> worker: {"type":"input","encoding":"base64","data_b64":"..."}   (binary-safe)
 *   parent -> worker: {"type":"resize","cols":N,"rows":N}
 *   parent -> worker: {"type":"close"}
 *   worker -> parent: {"type":"chunk","stream":"stdout","data":"..."}
 *   worker -> parent: {"type":"chunk","stream":"stdout","encoding":"base64","data_b64":"..."}
 *   worker -> parent: {"type":"exit","code":0}
 *   worker -> parent: {"type":"error","message":"..."}
 */
final class SshStreamingRunner implements StreamingRunnerInterface, PtySessionRunnerInterface
{
    public function __construct(
        private readonly string $phpBinary = PHP_BINARY,
        private readonly ?string $workerScript = null,
    ) {
    }

    public function stream(
        ServerConfig $config,
        string $command,
        callable $onChunk,
        ?callable $inputProvider = null,
        ?float $timeout = null,
        bool $allowFallback = false,
    ): int {
        $payload = [
            'type' => 'exec',
            'config' => $config->toArray(),
            'command' => $command,
            'timeout' => $timeout ?? $config->commandTimeout,
            'allowFallback' => $allowFallback,
        ];

        return $this->runWorker($payload, $onChunk, $inputProvider);
    }

    public function openShell(
        ServerConfig $config,
        callable $onChunk,
        ?callable $inputProvider = null,
        ?float $timeout = null,
    ): int {
        $payload = [
            'type' => 'shell',
            'config' => $config->toArray(),
            'timeout' => $timeout ?? $config->commandTimeout,
        ];

        return $this->runWorker($payload, $onChunk, $inputProvider);
    }

    public function openPtySession(
        ServerConfig $config,
        ?PtyConfig $pty = null,
        ?Cancellation $cancellation = null,
    ): PtySessionInterface {
        if ($cancellation !== null) {
            $cancellation->throwIfRequested();
        }

        $script = $this->workerScript
            ?? dirname(__DIR__, 2) . '/src/Transport/Worker/streaming-worker.php';

        $process = Process::start([$this->phpBinary, $script]);

        $payload = [
            'type' => 'shell',
            'config' => $config->toArray(),
            // 0 disables the worker-side idle timeout; interactive shells can
            // stay open for the whole browser tab lifetime.
            'timeout' => 0,
            'pty' => ($pty ?? new PtyConfig())->toArray(),
        ];

        try {
            $process->getStdin()->write(
                json_encode($payload, JSON_THROW_ON_ERROR) . "\n"
            );
        } catch (\Throwable $e) {
            try {
                $process->kill();
            } catch (\Throwable) {
                // Ignore.
            }
            throw new WorkerException(
                'Failed to send initial payload to PTY worker: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return new PtySession($process);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function runWorker(
        array $payload,
        callable $onChunk,
        ?callable $inputProvider,
    ): int {
        $script = $this->workerScript
            ?? dirname(__DIR__, 2) . '/src/Transport/Worker/streaming-worker.php';

        $process = Process::start([$this->phpBinary, $script]);
        $process->getStdin()->write(json_encode($payload, JSON_THROW_ON_ERROR) . "\n");

        $exitCode = 0;
        $buffer = '';
        $stderrBuffer = '';
        $stream = $process->getStdout();
        $stderrStream = $process->getStderr();

        $stderrTask = async(function () use ($stderrStream, &$stderrBuffer): void {
            while (($chunk = $stderrStream->read()) !== null) {
                $stderrBuffer .= $chunk;
            }
        });

        $inputTask = $inputProvider === null ? null : async(function () use ($process, $inputProvider): void {
            while (true) {
                try {
                    $input = $inputProvider();
                    if ($input !== null && $input !== '') {
                        $process->getStdin()->write(json_encode([
                            'type' => 'input',
                            'data' => $input,
                        ], JSON_THROW_ON_ERROR) . "\n");
                    }
                } catch (\Throwable) {
                    return;
                }
                delay(0.02);
            }
        });

        try {
            while (true) {
                $chunk = $stream->read();
                if ($chunk === null) {
                    throw new WorkerException($this->buildClosedStdoutMessage($stderrBuffer));
                }
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '') {
                        continue;
                    }
                    $msg = json_decode($line, true);
                    if (!is_array($msg)) {
                        continue;
                    }
                    $type = $msg['type'] ?? '';
                    if ($type === 'chunk') {
                        $data = $this->decodeChunk($msg);
                        $onChunk((string) ($msg['stream'] ?? 'stdout'), $data);
                    } elseif ($type === 'exit') {
                        $exitCode = (int) ($msg['code'] ?? 0);
                        break 2;
                    } elseif ($type === 'error') {
                        throw new WorkerException((string) ($msg['message'] ?? 'Streaming worker error'));
                    }
                }
            }
        } finally {
            try {
                if ($process->isRunning()) {
                    $process->getStdin()->write(json_encode(['type' => 'close']) . "\n");
                }
            } catch (\Throwable) {
            }
            try {
                $process->kill();
            } catch (\Throwable) {
            }
            unset($inputTask);
            $stderrTask->await();
        }

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function decodeChunk(array $msg): string
    {
        if (($msg['encoding'] ?? '') === 'base64' && isset($msg['data_b64']) && is_string($msg['data_b64'])) {
            $decoded = base64_decode($msg['data_b64'], true);
            return $decoded !== false ? $decoded : '';
        }

        return (string) ($msg['data'] ?? '');
    }

    private function buildClosedStdoutMessage(string $stderrBuffer): string
    {
        $stderr = trim($stderrBuffer);
        if ($stderr === '') {
            return 'Streaming worker closed stdout unexpectedly.';
        }

        return 'Streaming worker closed stdout unexpectedly. Worker stderr: ' . $stderr;
    }
}
