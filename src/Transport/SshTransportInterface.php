<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Result\CommandResult;
use AfazTech\LinOps\ServerConfig;

/**
 * Blocking SSH transport contract.
 *
 * Implementations MUST NOT be used directly on the Amp event loop.
 * They are executed inside isolated worker processes by WorkerPoolTransport.
 */
interface SshTransportInterface
{
    public function connect(ServerConfig $config): void;

    public function disconnect(): void;

    public function isConnected(): bool;

    /**
     * Execute one or more shell commands on an already-connected session.
     *
     * @param list<string> $commands
     * @return list<CommandResult>
     */
    public function executeCommands(array $commands, ?float $timeoutOverride = null): array;
}
