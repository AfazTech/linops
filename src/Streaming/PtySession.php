<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use AfazTech\LinOps\Exception\LinOpsException;
use Amp\ByteStream\ClosedException;
use Amp\Cancellation;
use Amp\Process\Process;

/**
 * Amp-native PTY session backed by a streaming-worker.php subprocess.
 *
 * Implements IteratorAggregate explicitly because PtySessionInterface
 * extends Amp's ReadableStream/WritableStream, which reach Traversable.
 * PHP requires a concrete class to name Iterator or IteratorAggregate
 * in its implements list; otherwise loading the class fatals with
 * "must implement interface Traversable as part of either Iterator or
 * IteratorAggregate".
 */
final class PtySession implements \IteratorAggregate, PtySessionInterface
{
    private ?int $exitCode = null;
    private bool $closed = false;
    private bool $writable = true;
    private string $readBuffer = '';

    /** @var list<\Closure> */
    private array $closeCallbacks = [];

    public function __construct(
        private readonly Process $process,
    ) {
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        while (true) {
            $line = $this->nextLine($cancellation);

            if ($line === null) {
                $this->markClosed($this->exitCode ?? -1);
                return null;
            }

            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                continue;
            }

            $type = $msg['type'] ?? '';

            if ($type === 'chunk') {
                $data = $this->decodeChunk($msg);
                if ($data !== '') {
                    return $data;
                }
                continue;
            }

            if ($type === 'exit') {
                $this->markClosed((int) ($msg['code'] ?? 0));
                return null;
            }

            if ($type === 'error') {
                $this->markClosed(-1);
                throw new LinOpsException((string) ($msg['message'] ?? 'PTY session error'));
            }
        }
    }

    public function getIterator(): \Traversable
    {
        while (($chunk = $this->read()) !== null) {
            yield $chunk;
        }
    }

    public function write(string $bytes): void
    {
        if (!$this->isWritable()) {
            throw new ClosedException('PTY session is not writable');
        }

        $this->send([
            'type' => 'input',
            'encoding' => 'base64',
            'data_b64' => base64_encode($bytes),
        ]);
    }

    public function end(): void
    {
        if (!$this->writable) {
            return;
        }
        $this->writable = false;
        if (!$this->closed) {
            try {
                $this->send(['type' => 'close']);
            } catch (\Throwable) {
                // Process may have already exited; ignore.
            }
        }
    }

    public function isWritable(): bool
    {
        return $this->writable && !$this->closed;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function resize(int $cols, int $rows): void
    {
        if ($this->closed || $cols <= 0 || $rows <= 0) {
            return;
        }
        try {
            $this->send(['type' => 'resize', 'cols' => $cols, 'rows' => $rows]);
        } catch (\Throwable) {
            // Best-effort; the worker may have already exited.
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->send(['type' => 'close']);
        } catch (\Throwable) {
            // Best-effort.
        }
        $this->markClosed($this->exitCode ?? -1);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
            return;
        }
        $this->closeCallbacks[] = $onClose;
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        try {
            if ($this->process->isRunning()) {
                $this->process->kill();
            }
        } catch (\Throwable) {
            // Ignore.
        }
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

    private function nextLine(?Cancellation $cancellation): ?string
    {
        while (true) {
            $pos = strpos($this->readBuffer, "\n");
            if ($pos !== false) {
                $line = substr($this->readBuffer, 0, $pos);
                $this->readBuffer = substr($this->readBuffer, $pos + 1);
                if ($line === '') {
                    continue;
                }
                return $line;
            }

            $chunk = $this->process->getStdout()->read($cancellation);
            if ($chunk === null) {
                if ($this->readBuffer !== '') {
                    $line = $this->readBuffer;
                    $this->readBuffer = '';
                    return $line;
                }
                return null;
            }
            $this->readBuffer .= $chunk;
        }
    }

    /** @param array<string, mixed> $msg */
    private function send(array $msg): void
    {
        $this->process->getStdin()->write(
            json_encode($msg, JSON_THROW_ON_ERROR) . "\n"
        );
    }

    private function markClosed(int $exitCode): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->writable = false;
        if ($this->exitCode === null) {
            $this->exitCode = $exitCode;
        }

        try {
            if ($this->process->isRunning()) {
                $this->process->kill();
            }
        } catch (\Throwable) {
            // Ignore.
        }

        $callbacks = $this->closeCallbacks;
        $this->closeCallbacks = [];
        foreach ($callbacks as $cb) {
            try {
                $cb();
            } catch (\Throwable) {
                // Ignore callback errors.
            }
        }
    }
}
