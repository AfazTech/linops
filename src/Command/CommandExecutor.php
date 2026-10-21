<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Command;

use AfazTech\LinOps\ConnectionMode;
use AfazTech\LinOps\Result\CommandResult;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Transport\WorkerPoolTransport;
use Amp\Cancellation;

/**
 * Dispatches command execution based on ServerConfig::$mode.
 *
 * Every command is wrapped with ServerConfig::wrapCommand() before
 * dispatch, so a `sudo: true` config transparently escalates via
 * `sudo -n` (no password prompt, requires NOPASSWD in sudoers).
 */
final class CommandExecutor
{
    private readonly LocalCommandRunner $localRunner;

    public function __construct(
        private readonly WorkerPoolTransport $transport,
        ?LocalCommandRunner $localRunner = null,
    ) {
        $this->localRunner = $localRunner ?? new LocalCommandRunner();
    }

    /**
     * @param list<string> $commands
     * @return list<CommandResult>
     */
    public function execute(
        ServerConfig $config,
        array $commands,
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): array {
        $effectiveTimeout = $timeout ?? $config->commandTimeout;

        $wrapped = array_map(
            static fn (string $c): string => $config->wrapCommand($c),
            $commands,
        );

        return match ($config->mode) {
            ConnectionMode::SSH => $this->transport->execute(
                $config,
                $wrapped,
                $timeout,
                $cancellation,
            ),
            ConnectionMode::LOCAL => $this->localRunner->execute(
                $wrapped,
                $effectiveTimeout,
                $cancellation,
            ),
        };
    }

    public function executeSingle(
        ServerConfig $config,
        string $command,
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): CommandResult {
        $results = $this->execute($config, [$command], $timeout, $cancellation);

        return $results[0] ?? new CommandResult(
            command: $command,
            success: false,
            error: 'No result returned by executor.',
        );
    }
}
