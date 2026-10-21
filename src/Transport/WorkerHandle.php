<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Exception\WorkerException;
use Amp\Cancellation;
use Amp\Process\Process;

/**
 * @internal
 */
final class WorkerHandle
{
    private bool $invalid = false;
    private string $stdoutBuffer = '';

    public function __construct(
        public readonly Process $process,
    ) {
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
     * so a subsequent request cannot read a stale response.
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
                throw new WorkerException('Worker closed stdout unexpectedly.');
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
}
