<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Integration;

use AfazTech\LinOps\Result\CommandResult;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Transport\SshTransportInterface;

/**
 * In-memory transport for integration tests. Never touches the network.
 */
final class MockTransport implements SshTransportInterface
{
    /** @var array<string, list<CommandResult>> */
    private array $responses = [];

    private bool $connected = false;

    /**
     * @param array<string, list<CommandResult>> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function connect(ServerConfig $config): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function executeCommands(array $commands, ?float $timeoutOverride = null): array
    {
        $results = [];
        foreach ($commands as $command) {
            if (isset($this->responses[$command])) {
                $results[] = $this->responses[$command][0];
                $this->responses[$command] = array_slice($this->responses[$command], 1);
            } else {
                $results[] = new CommandResult(
                    command: $command,
                    success: true,
                    stdout: '',
                    exitCode: 0,
                );
            }
        }

        return $results;
    }
}
