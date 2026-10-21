<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Exception\WorkerException;
use Amp\Cancellation;
use Amp\Future;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use function Amp\async;

/**
 * @internal
 */
final class WorkerHandle
{
    private const MAX_STDERR_BUFFER = 64 * 1024;
    private const STDERR_DRAIN_TIMEOUT = 0.2;

    private bool $invalid = false;
    private string $stdoutBuffer = '';
    private string $stderrBuffer = '';

    private readonly Future $stderrDrain;

    public function __construct(
        public readonly Process $process,
    ) {
        // Continuously drain stderr. Without this, a PHP fatal error in the
        // worker (written to stderr) deadlocks the pipe, and the caller only
        // sees "Worker closed stdout unexpectedly" with no details.
        $this->stderrDrain = async(function (): void {
            try {
                while (($chunk = $this->process->getStderr()->read()) !== null) {
                    if (strlen($this->stderrBuffer) < self::MAX_STDERR_BUFFER) {
                        $this->stderrBuffer .= $chunk;
                    }
                }
            } catch (\Throwable) {
                // Stream closed or process killed; nothing more to capture.
            }
        });
    }

    public function isAlive(): bool
    {
        if ($this->invalid) {
            return false;
        }

        try {
            return $this->process->isRunning();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Read one newline-terminated line from the worker's stdout.
     *
     * On cancellation, the handle is invalidated and the process is killed
     * so a subsequent request cannot read a stale response. If the worker
     * closes stdout, we wait briefly for the stderr drain to finish and
     * include its contents in the exception message.
     */
    public function readLine(?Cancellation $cancellation = null): string
    {
        while (true) {
            $pos = strpos($this->stdoutBuffer, "\n");
            if ($pos !== false) {
                $line = substr($this->stdoutBuffer, 0, $pos);
                $this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);
                return $line;
            }

            try {
                $chunk = $this->process->getStdout()->read($cancellation);
            } catch (\Throwable $e) {
                $this->invalidate();
                throw $e;
            }

            if ($chunk === null) {
                $this->invalid = true;
                $this->flushStderr();
                throw new WorkerException($this->buildClosedMessage());
            }

            $this->stdoutBuffer .= $chunk;
        }
    }

    public function invalidate(): void
    {
        $this->invalid = true;

        try {
            if ($this->process->isRunning()) {
                $this->process->kill();
            }
        } catch (\Throwable) {
            // Process may already be gone.
        }
    }

    public function close(): void
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->getStdin()->write(
                    json_encode(['type' => 'close'], JSON_THROW_ON_ERROR) . "\n"
                );
            }
        } catch (\Throwable) {
            // Best-effort graceful shutdown.
        }

        try {
            $this->process->kill();
        } catch (\Throwable) {
            // Process may already be gone.
        }

        $this->invalid = true;
        $this->flushStderr();
    }

    public function __destruct()
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->kill();
            }
        } catch (\Throwable) {
            // Ignore.
        }
    }

    /**
     * Wait briefly for the stderr drain to finish so we can include the
     * worker's own error output in the exception message.
     */
    private function flushStderr(): void
    {
        if ($this->stderrDrain->isComplete()) {
            return;
        }

        try {
            $this->stderrDrain->await(new TimeoutCancellation(self::STDERR_DRAIN_TIMEOUT));
        } catch (\Throwable) {
            // Timeout or cancellation; use whatever we captured.
        }
    }

    private function buildClosedMessage(): string
    {
        $stderr = trim($this->stderrBuffer);
        if ($stderr === '') {
            return 'Worker closed stdout unexpectedly.';
        }

        return 'Worker closed stdout unexpectedly. Worker stderr: ' . $stderr;
    }
}
