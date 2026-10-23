<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use AfazTech\LinOps\ServerConfig;
use Amp\Cancellation;

/**
 * Additional streaming runner capability: open a live PTY session and return
 * it as an object, instead of consuming it to completion via a callback.
 *
 * Kept separate from StreamingRunnerInterface so that existing implementations
 * of the callback-based API are not forced to add the method.
 */
interface PtySessionRunnerInterface
{
    /**
     * Open a PTY-backed interactive shell and return it without waiting for
     * the shell to exit. The returned session's read() blocks (suspends the
     * current fiber) until remote output is available; write() forwards
     * bytes to the remote PTY.
     */
    public function openPtySession(
        ServerConfig $config,
        ?PtyConfig $pty = null,
        ?Cancellation $cancellation = null,
    ): PtySessionInterface;
}
