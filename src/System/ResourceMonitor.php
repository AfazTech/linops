<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\CpuLoadInfo;
use AfazTech\LinOps\Result\DiskInfo;
use AfazTech\LinOps\Result\MemoryInfo;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\CpuCalculator;
use AfazTech\LinOps\Support\UnitFormatter;
use DateTimeImmutable;

final class ResourceMonitor
{
    /** @var list<string> Kernel virtual filesystems (never shown) */
    private const KERNEL_FS = [
        'proc', 'sysfs', 'devpts', 'mqueue', 'hugetlbfs', 'debugfs',
        'tracefs', 'securityfs', 'pstore', 'bpf', 'autofs', 'configfs',
        'fusectl', 'binfmt_misc', 'nsfs', 'cgroup', 'cgroup2', 'efivarfs',
    ];

    /** @var list<string> Pseudo filesystems (hidden unless includePseudo=true) */
    private const PSEUDO_FS = [
        'devtmpfs', 'tmpfs', 'squashfs', 'overlay', 'rpc_pipefs', 'nfsd',
    ];

    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    public function getCpuLoad(ServerConfig $config, float $sampleInterval = 1.0): CpuLoadInfo
    {
        $command = sprintf(
            'cat /proc/loadavg; echo "---"; grep -c ^processor /proc/cpuinfo; echo "---"; grep \'^cpu \' /proc/stat; sleep %s; echo "---"; grep \'^cpu \' /proc/stat',
            max(0.1, $sampleInterval),
        );

        $result = $this->executor->executeSingle($config, $command, $sampleInterval + 5.0);
        $output = (string) $result->stdout;
        $sections = explode('---', $output);

        $loadParts = preg_split('/\s+/', trim($sections[0] ?? ''));
        $load1 = (float) ($loadParts[0] ?? 0);
        $load5 = (float) ($loadParts[1] ?? 0);
        $load15 = (float) ($loadParts[2] ?? 0);
        $cpus = max(1, (int) trim($sections[1] ?? '1'));

        $first = $this->parseCpuLine($sections[2] ?? '');
        $second = $this->parseCpuLine($sections[3] ?? '');
        $usage = ($first !== null && $second !== null)
            ? CpuCalculator::calculateUsage($first, $second)
            : null;

        return new CpuLoadInfo(
            load1: $load1,
            load5: $load5,
            load15: $load15,
            logicalCpuCount: $cpus,
            normalizedLoad: CpuCalculator::normalizeLoad($load1, $cpus),
            usagePercent: $usage['usage'] ?? null,
            userPercent: $usage['user'] ?? null,
            systemPercent: $usage['system'] ?? null,
            iowaitPercent: $usage['iowait'] ?? null,
            idlePercent: $usage['idle'] ?? null,
            sampleIntervalSeconds: $sampleInterval,
            collectedAt: new DateTimeImmutable(),
        );
    }

    /**
     * @return array{user: float, nice: float, system: float, idle: float, iowait: float, irq: float, softirq: float, steal: float}|null
     */
    private function parseCpuLine(string $section): ?array
    {
        $line = explode("\n", trim($section))[0] ?? '';
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'cpu ')) {
            return null;
        }

        $parts = preg_split('/\s+/', $line);
        if ($parts === false || count($parts) < 9) {
            return null;
        }

        return [
            'user' => (float) $parts[1],
            'nice' => (float) $parts[2],
            'system' => (float) $parts[3],
            'idle' => (float) $parts[4],
            'iowait' => (float) ($parts[5] ?? 0),
            'irq' => (float) ($parts[6] ?? 0),
            'softirq' => (float) ($parts[7] ?? 0),
            'steal' => (float) ($parts[8] ?? 0),
        ];
    }

    public function getMemory(ServerConfig $config): MemoryInfo
    {
        $result = $this->executor->executeSingle($config, 'cat /proc/meminfo');
        $values = [];

        foreach (explode("\n", (string) $result->stdout) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB$/', trim($line), $matches) === 1) {
                $values[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = $values['MemTotal'] ?? 0;
        $free = $values['MemFree'] ?? 0;
        $available = $values['MemAvailable'] ?? $free;
        $cached = $values['Cached'] ?? 0;
        $buffered = $values['Buffers'] ?? 0;
        $used = max(0, $total - $available);

        $swapTotal = $values['SwapTotal'] ?? 0;
        $swapFree = $values['SwapFree'] ?? 0;
        $swapUsed = max(0, $swapTotal - $swapFree);

        return new MemoryInfo(
            totalBytes: $total,
            usedBytes: $used,
            availableBytes: $available,
            freeBytes: $free,
            cachedBytes: $cached,
            bufferedBytes: $buffered,
            usagePercent: UnitFormatter::percent($used, $total),
            swapTotalBytes: $swapTotal,
            swapUsedBytes: $swapUsed,
            swapAvailableBytes: $swapFree,
            swapUsagePercent: UnitFormatter::percent($swapUsed, $swapTotal),
        );
    }

    /**
     * @param list<string>|null $mountPoints
     * @return list<DiskInfo>
     */
    public function getDisks(
        ServerConfig $config,
        ?array $mountPoints = null,
        bool $includePseudo = false,
    ): array {
        $result = $this->executor->executeSingle($config, 'df -PTk');
        if (!$result->success) {
            return [];
        }

        $inodeMap = $this->readInodes($config);

        $disks = [];
        $seen = [];

        $lines = explode("\n", trim((string) $result->stdout));
        array_shift($lines);

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if ($parts === false || count($parts) < 7) {
                continue;
            }

            $type = $parts[1];
            $mount = $parts[6];

            if (in_array($type, self::KERNEL_FS, true)) {
                continue;
            }
            if (!$includePseudo) {
                if (in_array($type, self::PSEUDO_FS, true) || str_starts_with($type, 'fuse.')) {
                    continue;
                }
            }

            if (isset($seen[$mount])) {
                continue;
            }
            $seen[$mount] = true;

            if ($mountPoints !== null && !in_array($mount, $mountPoints, true)) {
                continue;
            }

            $total = (int) $parts[2] * 1024;
            $used = (int) $parts[3] * 1024;
            $available = (int) $parts[4] * 1024;

            $inode = $inodeMap[$mount] ?? null;

            $disks[] = new DiskInfo(
                filesystem: $parts[0],
                mountPoint: $mount,
                type: $type,
                totalBytes: $total,
                usedBytes: $used,
                availableBytes: $available,
                usagePercent: UnitFormatter::percent($used, $total),
                inodeTotal: $inode['total'] ?? null,
                inodeUsed: $inode['used'] ?? null,
                inodeAvailable: $inode['available'] ?? null,
                inodeUsagePercent: $inode['percent'] ?? null,
                available: true,
            );
        }

        return $disks;
    }

    /**
     * @return array<string, array{total: int, used: int, available: int, percent: float}>
     */
    private function readInodes(ServerConfig $config): array
    {
        $result = $this->executor->executeSingle($config, 'df -iP');
        if (!$result->success) {
            return [];
        }

        $map = [];
        $lines = explode("\n", trim((string) $result->stdout));
        array_shift($lines);

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if ($parts === false || count($parts) < 6) {
                continue;
            }

            $total = (int) $parts[1];
            $used = (int) $parts[2];
            $free = (int) $parts[3];
            $mount = $parts[5];

            if ($total < 0) {
                continue;
            }

            $map[$mount] = [
                'total' => $total,
                'used' => $used,
                'available' => $free,
                'percent' => UnitFormatter::percent($used, $total),
            ];
        }

        return $map;
    }
}
