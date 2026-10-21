<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Service;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\ServiceRestartResult;
use AfazTech\LinOps\Result\ServiceStatus;
use AfazTech\LinOps\ServerConfig;

final class ServiceInspector
{
    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    /**
     * @return list<ServiceStatus>
     */
    public function listServices(ServerConfig $config, ?string $pattern = null): array
    {
        $command = 'systemctl list-units --type=service --all --no-legend --no-pager 2>/dev/null';
        $result = $this->executor->executeSingle($config, $command);

        if (!$result->success) {
            return [
                new ServiceStatus(
                    name: 'systemd',
                    description: null,
                    loadState: null,
                    activeState: null,
                    subState: null,
                    enabled: null,
                    mainPid: null,
                    supported: false,
                    error: 'systemctl is not available on this server.',
                ),
            ];
        }

        $services = [];
        foreach (explode("\n", (string) $result->stdout) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '.service')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 4) {
                continue;
            }
            $name = (string) $parts[0];
            if ($pattern !== null && preg_match($pattern, $name) !== 1) {
                continue;
            }
            $services[] = new ServiceStatus(
                name: $name,
                description: implode(' ', array_slice($parts, 4)),
                loadState: (string) $parts[1],
                activeState: (string) $parts[2],
                subState: (string) $parts[3],
                enabled: null,
                mainPid: null,
                supported: true,
                error: null,
            );
        }

        return $services;
    }

    public function getStatus(ServerConfig $config, string $service): ServiceStatus
    {
        $status = $this->queryStatus($config, $service);

        // Common alias: ssh <-> sshd on RHEL/Debian.
        if ($status->loadState === 'not-found') {
            $alias = match ($service) {
                'ssh' => 'sshd',
                'sshd' => 'ssh',
                default => null,
            };
            if ($alias !== null) {
                $aliased = $this->queryStatus($config, $alias);
                if ($aliased->loadState !== 'not-found') {
                    return $aliased;
                }
            }
        }

        return $status;
    }

    private function queryStatus(ServerConfig $config, string $service): ServiceStatus
    {
        $command = sprintf(
            'systemctl show %s --no-page -p LoadState,ActiveState,SubState,UnitFileState,MainPID,Description 2>/dev/null',
            escapeshellarg($service),
        );
        $result = $this->executor->executeSingle($config, $command);

        if (!$result->success || trim((string) $result->stdout) === '') {
            return new ServiceStatus(
                name: $service,
                description: null,
                loadState: null,
                activeState: null,
                subState: null,
                enabled: null,
                mainPid: null,
                supported: true,
                error: 'Service not found or systemctl unavailable.',
            );
        }

        $properties = [];
        foreach (explode("\n", trim((string) $result->stdout)) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $properties[$key] = $value;
            }
        }

        return new ServiceStatus(
            name: $service,
            description: $properties['Description'] ?? null,
            loadState: $properties['LoadState'] ?? null,
            activeState: $properties['ActiveState'] ?? null,
            subState: $properties['SubState'] ?? null,
            enabled: isset($properties['UnitFileState']) ? $properties['UnitFileState'] === 'enabled' : null,
            mainPid: isset($properties['MainPID']) ? (int) $properties['MainPID'] : null,
            supported: true,
            error: null,
        );
    }

    /**
     * Explicitly restart a service. Never called automatically.
     */
    public function restart(ServerConfig $config, string $service): ServiceRestartResult
    {
        $command = sprintf('systemctl restart %s 2>&1', escapeshellarg($service));
        $result = $this->executor->executeSingle($config, $command, max(30.0, $config->commandTimeout));

        return new ServiceRestartResult(
            service: $service,
            success: $result->success,
            exitCode: $result->exitCode,
            stdout: $result->stdout,
            stderr: $result->stderr,
            error: $result->error,
        );
    }
}
