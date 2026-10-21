<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Command;

use AfazTech\LinOps\Result\CommandResult;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use function Amp\async;
use function Amp\Future\await;

/**
 * Executes shell commands on the local host using Amp\Process (non-blocking).
 *
 * Each command runs in its own `sh -c` child process. Stdout/stderr are read
 * concurrently to avoid pipe deadlocks. Timeout and cancellation both kill the
 * child process and return a failure result with exitCode = -1.
 */
final class LocalCommandRunner
{
    public function __construct(
        private readonly int $maxOutputBytes = 1048576,
        private readonly string $shell = 'sh',
    ) {
    }

    /**
     * @param list<string> $commands
     * @return list<CommandResult>
     */
    public function execute(
        array $commands,
        float $timeout = 30.0,
        ?Cancellation $cancellation = null,
    ): array {
        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->executeOne($command, $timeout, $cancellation);
        }

        return $results;
    }

    private function executeOne(string $command, float $timeout, ?Cancellation $cancellation): CommandResult
    {
        $start = microtime(true);

        try {
            $process = Process::start([$this->shell, '-c', $command]);
        } catch (\Throwable $e) {
            return new CommandResult(
                command: $command,
                success: false,
                error: 'Failed to start local process: ' . $e->getMessage(),
                duration: microtime(true) - $start,
            );
        }

        $timeoutCancellation = new TimeoutCancellation(max(0.1, $timeout));
        $effective = $cancellation === null
            ? $timeoutCancellation
            : new CompositeCancellation($cancellation, $timeoutCancellation);

        try {
            $stdoutFuture = async(fn (): string => $this->readCapped($process->getStdout(), $effective));
            $stderrFuture = async(fn (): string => $this->readCapped($process->getStderr(), $effective));

            [$stdout, $stderr] = await([$stdoutFuture, $stderrFuture], $effective);
            $exitCode = $process->join($effective);

            return new CommandResult(
                command: $command,
                success: $exitCode === 0,
                stdout: $stdout,
                stderr: $stderr,
                exitCode: $exitCode,
                duration: microtime(true) - $start,
            );
        } catch (\Throwable $e) {
            try {
                $process->kill();
            } catch (\Throwable) {
                // Process may already be gone.
            }

            $timedOut = $e instanceof CancelledException;
            return new CommandResult(
                command: $command,
                success: false,
                error: $timedOut
                    ? 'Local command timed out or was cancelled.'
                    : $e->getMessage(),
                exitCode: $timedOut ? -1 : null,
                duration: microtime(true) - $start,
            );
        }
    }

    /**
     * Drain the stream fully so the child process can exit, but keep only
     * the first $maxOutputBytes bytes.
     */
    private function readCapped(ReadableStream $stream, Cancellation $cancellation): string
    {
        $buffer = '';
        $truncated = false;

        while (($chunk = $stream->read($cancellation)) !== null) {
            if ($truncated) {
                continue;
            }

            $buffer .= $chunk;
            if (strlen($buffer) > $this->maxOutputBytes) {
                $buffer = substr($buffer, 0, $this->maxOutputBytes);
                $truncated = true;
            }
        }

        return $buffer;
    }
}
