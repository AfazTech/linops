<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\HostInfo;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\OperatingSystemFamily;

final class SystemInspector
{
    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    public function getHostInfo(ServerConfig $config): HostInfo
    {
        $command = <<<'SH'
set -e
hostname=$(hostname 2>/dev/null || cat /etc/hostname 2>/dev/null || echo unknown)
os_name="unknown"
os_id="unknown"
os_version="unknown"
if [ -r /etc/os-release ]; then
  . /etc/os-release
  os_name="${NAME:-unknown}"
  os_id="${ID:-unknown}"
  os_version="${VERSION_ID:-unknown}"
fi
kernel=$(uname -r 2>/dev/null || echo unknown)
arch=$(uname -m 2>/dev/null || echo unknown)
uptime=$(cut -d. -f1 /proc/uptime 2>/dev/null || echo 0)
cpus=$(nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo 2>/dev/null || echo 0)
printf '%s\n' "$hostname" "$os_name" "$os_id" "$os_version" "$kernel" "$arch" "$uptime" "$cpus"
SH;

        $result = $this->executor->executeSingle($config, $command);
        $lines = $result->success ? explode("\n", trim((string) $result->stdout)) : [];

        $get = static fn (int $index, string $default = 'unknown'): string =>
            isset($lines[$index]) && trim($lines[$index]) !== '' ? trim($lines[$index]) : $default;

        return new HostInfo(
            hostname: $get(0),
            operatingSystem: $get(1),
            distributionId: $get(2),
            distributionVersion: $get(3),
            kernelVersion: $get(4),
            architecture: $get(5),
            uptimeSeconds: (float) $get(6, '0'),
            logicalCpuCount: (int) $get(7, '0'),
        );
    }

    public function detectFamily(ServerConfig $config): OperatingSystemFamily
    {
        $command = 'if [ -r /etc/os-release ]; then . /etc/os-release; echo "${ID:-}"; fi';
        $result = $this->executor->executeSingle($config, $command);
        $id = strtolower(trim((string) $result->stdout));

        return match (true) {
            in_array($id, ['rhel', 'centos', 'rocky', 'almalinux', 'fedora', 'ol', 'oracle'], true)
                => OperatingSystemFamily::RHEL,
            in_array($id, ['debian', 'ubuntu', 'linuxmint', 'pop'], true)
                => OperatingSystemFamily::DEBIAN,
            default => OperatingSystemFamily::UNKNOWN,
        };
    }
}
