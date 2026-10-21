<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\OutputMode;
use AfazTech\LinOps\Result\LoginRecord;
use AfazTech\LinOps\ServerConfig;
use DateTimeImmutable;

final class LogInspector
{
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly \AfazTech\LinOps\LinOps $linops,
    ) {
    }

    /**
     * @return list<LoginRecord>
     */
    public function lastLogins(ServerConfig $config, int $limit = 20): array
    {
        $result = $this->executor->executeSingle(
            $config,
            sprintf('last -F -n %d 2>/dev/null', max(1, $limit)),
        );

        if (!$result->success) {
            return [];
        }

        return $this->parseLastOutput((string) $result->stdout, true);
    }

    /**
     * @return list<LoginRecord>
     */
    public function getFailedLogins(ServerConfig $config, int $limit = 20): array
    {
        $result = $this->executor->executeSingle(
            $config,
            sprintf('lastb -F -n %d 2>/dev/null', max(1, $limit)),
        );

        if (!$result->success) {
            return [];
        }

        return $this->parseLastOutput((string) $result->stdout, false);
    }

    /**
     * @return list<LoginRecord>
     */
    private function parseLastOutput(string $output, bool $success): array
    {
        $records = [];

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'wtmp begins') || str_starts_with($line, 'btmp begins')) {
                continue;
            }

            if (preg_match(
                '/^(\S+)\s+(\S+)\s+(\S+)\s+(\w{3}\s+\w{3}\s+\d+\s+\d+:\d+:\d+\s+\d{4})\s+(.+)$/',
                $line,
                $m,
            ) === 1) {
                $ts = DateTimeImmutable::createFromFormat('D M j H:i:s Y', $m[4]);
                $records[] = new LoginRecord(
                    user: $m[1],
                    tty: $m[2],
                    host: $m[3],
                    timestamp: $ts !== false ? $ts : null,
                    success: $success,
                    details: trim($m[5]),
                    raw: $line,
                );
                continue;
            }

            $records[] = new LoginRecord(
                user: '',
                tty: null,
                host: null,
                timestamp: null,
                success: $success,
                details: null,
                raw: $line,
            );
        }

        return $records;
    }

    /**
     * Follow a remote file with tail -f for the given duration, collecting
     * whatever was written during that window.
     */
    public function tailFile(ServerConfig $config, string $path, float $duration = 60.0): string
    {
        return $this->follow(
            $config,
            'tail -f -- ' . escapeshellarg($path),
            $duration,
        );
    }

    /**
     * Follow a journalctl unit for the given duration, collecting whatever
     * was written during that window.
     */
    public function journalFollow(ServerConfig $config, string $unit, float $duration = 60.0): string
    {
        return $this->follow(
            $config,
            'journalctl -f -u ' . escapeshellarg($unit),
            $duration,
        );
    }

    private function follow(ServerConfig $config, string $command, float $duration): string
    {
        $effective = $config->wrapCommand($command);
        $collected = '';

        try {
            $this->linops->executeCommand(
                $effective,
                OutputMode::STREAM,
                static function (string $stream, string $data) use (&$collected): void {
                    $collected .= $data;
                },
                null,
                $duration,
                false,
            );
        } catch (\Throwable) {
            // Output collected so far is still returned.
        }

        return preg_replace(
            '/\n?\[linops\] timeout reached, closing session\n?/',
            "\n",
            $collected,
        ) ?? $collected;
    }
}
