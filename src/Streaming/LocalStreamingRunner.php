<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use AfazTech\LinOps\ServerConfig;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/**
 * Streams a local command or interactive shell using Amp\Process.
 *
 * Stdout and stderr are read concurrently and dispatched to $onChunk as
 * they arrive. The input provider is polled every 20 ms and forwarded to
 * stdin.
 *
 * Note: the local shell is spawned without a dedicated PTY, so `openShell()`
 * uses `sh -i` which is close but not identical to a full terminal.
 */
final class LocalStreamingRunner implements StreamingRunnerInterface
{
    public function __construct(
        private readonly string $shell = 'sh',
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
        // $allowFallback has no meaning for local execution.
        unset($allowFallback);

        $effectiveTimeout = $timeout ?? $config->commandTimeout;
        $cancellation = new TimeoutCancellation(max(0.1, $effectiveTimeout));
        $process = Process::start([$this->shell, '-c', $command]);

        return $this->relay($process, $onChunk, $inputProvider, $cancellation);
    }

    public function openShell(
        ServerConfig $config,
        callable $onChunk,
        ?callable $inputProvider = null,
        ?float $timeout = null,
    ): int {
        $effectiveTimeout = $timeout ?? $config->commandTimeout;
        $cancellation = new TimeoutCancellation(max(0.1, $effectiveTimeout));
        $process = Process::start([$this->shell, '-i']);

        return $this->relay($process, $onChunk, $inputProvider, $cancellation);
    }

    private function relay(
        Process $process,
        callable $onChunk,
        ?callable $inputProvider,
        TimeoutCancellation $cancellation,
    ): int {
        $reader = async(function () use ($process, $onChunk, $cancellation): void {
            $stdoutTask = async(function () use ($process, $onChunk, $cancellation): void {
                while (($chunk = $process->getStdout()->read($cancellation)) !== null) {
                    $onChunk('stdout', $chunk);
                }
            });

            $stderrTask = async(function () use ($process, $onChunk, $cancellation): void {
                while (($chunk = $process->getStderr()->read($cancellation)) !== null) {
                    $onChunk('stderr', $chunk);
                }
            });

            await([$stdoutTask, $stderrTask], $cancellation);
        });

        $inputTask = null;
        if ($inputProvider !== null) {
            $inputTask = async(function () use ($process, $inputProvider, $cancellation): void {
                while (true) {
                    $cancellation->throwIfRequested();
                    try {
                        $input = $inputProvider();
                        if ($input !== null && $input !== '') {
                            $process->getStdin()->write($input);
                        }
                    } catch (\Throwable) {
                        return;
                    }
                    delay(0.02);
                }
            });
        }

        try {
            $exitCode = $process->join($cancellation);
            $reader->await($cancellation);
            return $exitCode;
        } catch (\Throwable) {
            try {
                $process->kill();
            } catch (\Throwable) {
            }
            return -1;
        } finally {
            unset($inputTask);
        }
    }
}
