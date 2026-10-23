<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;

/**
 * A live PTY-backed shell session.
 *
 * ReadableStream: yields chunks of remote output as they arrive; returns
 * null on EOF (shell exited). Supports the standard Amp `foreach` and
 * `Amp\ByteStream\pipe()` patterns.
 *
 * WritableStream: accepts raw input bytes (keyboard input, control
 * characters, ANSI sequences); forwarded as-is to the remote PTY.
 *
 * Both sides are safe to use from independent fibers — reads and writes do
 * not block each other, which is what a multi-tab web terminal needs.
 */
interface PtySessionInterface extends ReadableStream, WritableStream
{
    /**
     * Send a window-change request (SIGWINCH) to the remote PTY.
     *
     * Best-effort: some SSH servers and phpseclib versions do not support
     * runtime window-change requests. In that case the call is a no-op and
     * programs like vim or top keep their initial dimensions.
     */
    public function resize(int $cols, int $rows): void;

    /**
     * Exit code of the shell, or null if the session is still running or the
     * process was killed before a clean exit message arrived.
     *
     * Only meaningful after read() has returned null (EOF).
     */
    public function getExitCode(): ?int;
}
