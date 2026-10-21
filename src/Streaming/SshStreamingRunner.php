<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use AfazTech\LinOps\Exception\WorkerException;
use AfazTech\LinOps\ServerConfig;
use Amp\Process\Process;
use function Amp\async;
use function Amp\delay;

/**
 * Streams a remote command or shell over SSH by spawning a dedicated worker
 * process that uses phpseclib in PTY mode.
 *
 * Protocol (parent ↔ worker), one JSON object per line:
 *   parent → worker: {"type":"exec","config":{...},"command":"...","timeout":N,"allowFallback":bool}
 *   parent → worker: {"type":"shell","config":{...},"timeout":N}
 *   parent → worker: {"type":"input","data":"y\n"}
 *   parent → worker: {"type":"close"}
 *   worker → parent: {"type":"chunk","stream":"stdout","data":"..."}
 *   worker → parent: {"type":"exit","code":0}
 *   worker → parent: {"type":"error","message":"..."}
 */
final class SshStreamingRunner implements StreamingRunnerInterface
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
                        $onChunk((string) ($msg['stream'] ?? 'stdout'), (string) ($msg['data'] ?? ''));
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

    private function buildClosedStdoutMessage(string $stderrBuffer): string
    {
        $stderr = trim($stderrBuffer);
        if ($stderr === '') {
            return 'Streaming worker closed stdout unexpectedly.';
        }

        return 'Streaming worker closed stdout unexpectedly. Worker stderr: ' . $stderr;
    }
}
