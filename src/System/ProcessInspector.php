<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\ListeningPort;
use AfazTech\LinOps\Result\ProcessInfo;
use AfazTech\LinOps\ServerConfig;

final class ProcessInspector
{
    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    /**
     * @return list<ProcessInfo>
     */
    public function listProcesses(ServerConfig $config): array
    {
        $result = $this->executor->executeSingle(
            $config,
            'ps -eo pid,ppid,user,pcpu,pmem,rss,stat,args --no-headers 2>/dev/null',
        );

        if (!$result->success) {
            return [];
        }

        $processes = [];
        foreach (explode("\n", (string) $result->stdout) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match(
                '/^\s*(\d+)\s+(\d+)\s+(\S+)\s+([\d.]+)\s+([\d.]+)\s+(\d+)\s+(\S+)\s+(.*)$/',
                $line,
                $m,
            ) !== 1) {
                continue;
            }

            $processes[] = new ProcessInfo(
                pid: (int) $m[1],
                ppid: (int) $m[2],
                user: $m[3],
                cpuPercent: (float) $m[4],
                memPercent: (float) $m[5],
                rssKb: (int) $m[6],
                state: $m[7],
                command: trim($m[8]),
            );
        }

        return $processes;
    }

    /**
     * @return list<ListeningPort>
     */
    public function listListeningPorts(ServerConfig $config): array
    {
        $result = $this->executor->executeSingle($config, 'ss -tulpnH 2>/dev/null');

        if ($result->success && trim((string) $result->stdout) !== '') {
            return $this->parseSs((string) $result->stdout);
        }

        $result = $this->executor->executeSingle($config, 'netstat -tulpn 2>/dev/null');
        if (!$result->success) {
            return [];
        }

        return $this->parseNetstat((string) $result->stdout);
    }

    /**
     * @return list<ListeningPort>
     */
    private function parseSs(string $output): array
    {
        $ports = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match(
                '/^(tcp|udp)\s+\S+\s+\d+\s+\d+\s+(\S+):(\d+)\s+\S+\s+(.+)$/',
                $line,
                $m,
            ) !== 1) {
                continue;
            }

            $processName = null;
            $pid = null;
            if (preg_match('/\("([^"]+)",pid=(\d+)/', $m[4], $pm) === 1) {
                $processName = $pm[1];
                $pid = (int) $pm[2];
            }

            $ports[] = new ListeningPort(
                protocol: $m[1],
                address: $m[2],
                port: (int) $m[3],
                processName: $processName,
                pid: $pid,
            );
        }

        return $ports;
    }

    /**
     * @return list<ListeningPort>
     */
    private function parseNetstat(string $output): array
    {
        $ports = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match(
                '/^(tcp|udp)\d*\s+\d+\s+\d+\s+(\S+):(\d+)\s+\S+\s+\S+\s+(\d+)\/(\S+)$/',
                $line,
                $m,
            ) !== 1) {
                continue;
            }

            $ports[] = new ListeningPort(
                protocol: $m[1],
                address: $m[2],
                port: (int) $m[3],
                processName: $m[5],
                pid: (int) $m[4],
            );
        }

        return $ports;
    }
}
