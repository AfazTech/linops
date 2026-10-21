<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

use AfazTech\LinOps\ServerConfig;

interface StreamingRunnerInterface
{
    /**
     * Execute a command and stream its output in real time.
     *
     * @param callable(string $stream, string $data): void $onChunk
     *        Called for every available chunk as soon as it arrives.
     *        $stream is "stdout" or "stderr".
     * @param null|callable():?string $inputProvider
     *        Called periodically. Return a string to send to the command's
     *        stdin, or null when no input is available.
     * @param bool $allowFallback
     *        If true and the SSH server refuses the PTY shell channel, retry
     *        the command via SSH2::exec(). DANGEROUS for non-idempotent
     *        commands (e.g. yum update) and disables stdin forwarding.
     *        Defaults to false so callers must opt in explicitly.
     * @return int Process exit code. Returns -1 on timeout/cancellation.
     */
    public function stream(
        ServerConfig $config,
        string $command,
        callable $onChunk,
        ?callable $inputProvider = null,
        ?float $timeout = null,
        bool $allowFallback = false,
    ): int;

    /**
     * Open an interactive shell session without running a specific command.
     *
     * Requires a real PTY on the remote side. Throws WorkerException if
     * the server refuses to open the shell channel (no exec fallback is
     * possible because exec() cannot receive stdin).
     *
     * @param callable(string $stream, string $data): void $onChunk
     * @param null|callable():?string $inputProvider
     * @return int Shell exit code (0 on clean close, -1 on timeout).
     */
    public function openShell(
        ServerConfig $config,
        callable $onChunk,
        ?callable $inputProvider = null,
        ?float $timeout = null,
    ): int;
}
