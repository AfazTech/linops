<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\OperatingSystemFamily;

/**
 * Reboot detection returns a simple boolean.
 *
 * Returns true if either signal positively indicates a reboot is required:
 *   1. Latest installed kernel differs from the running kernel.
 *   2. The OS-specific reboot marker/tool reports a reboot is required.
 *
 * Returns false when neither signal fires, including when tools are missing.
 */
final class RebootManager
{
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly SystemInspector $systemInspector,
    ) {
    }

    public function needsReboot(ServerConfig $config): bool
    {
        $family = $this->systemInspector->detectFamily($config);

        if ($family === OperatingSystemFamily::UNKNOWN) {
            return false;
        }

        $running = $this->getRunningKernel($config);
        $installed = $this->getLatestInstalledKernel($config, $family);

        if ($running !== null && $installed !== null && $running !== $installed) {
            return true;
        }

        return match ($family) {
            OperatingSystemFamily::RHEL => $this->rhelNeedsRestart($config),
            OperatingSystemFamily::DEBIAN => $this->debianNeedsRestart($config),
            OperatingSystemFamily::UNKNOWN => false,
        };
    }

    private function getRunningKernel(ServerConfig $config): ?string
    {
        $result = $this->executor->executeSingle($config, 'uname -r 2>/dev/null');
        $kernel = trim((string) $result->stdout);

        return $kernel !== '' ? $kernel : null;
    }

    private function getLatestInstalledKernel(ServerConfig $config, OperatingSystemFamily $family): ?string
    {
        $command = match ($family) {
            OperatingSystemFamily::RHEL => 'rpm -q kernel --qf \'%{VERSION}-%{RELEASE}.%{ARCH}\n\' 2>/dev/null | sort -V | tail -1',
            OperatingSystemFamily::DEBIAN => 'dpkg-query -W -f=\'${Package}\n\' \'linux-image-*\' 2>/dev/null | grep -E \'^linux-image-[0-9]\' | sed \'s/^linux-image-//\' | sort -V | tail -1',
            OperatingSystemFamily::UNKNOWN => 'echo ""',
        };

        $result = $this->executor->executeSingle($config, $command);
        $kernel = trim((string) $result->stdout);

        return $kernel !== '' ? $kernel : null;
    }

    private function rhelNeedsRestart(ServerConfig $config): bool
    {
        $command = 'if command -v needs-restarting >/dev/null 2>&1; then '
            . 'needs-restarting -r >/dev/null 2>&1; echo "EXIT:$?"; '
            . 'elif [ -x /usr/bin/needs-restarting ]; then '
            . '/usr/bin/needs-restarting -r >/dev/null 2>&1; echo "EXIT:$?"; '
            . 'elif [ -x /usr/sbin/needs-restarting ]; then '
            . '/usr/sbin/needs-restarting -r >/dev/null 2>&1; echo "EXIT:$?"; '
            . 'else echo "NO_TOOL"; fi';

        $result = $this->executor->executeSingle($config, $command);
        $output = (string) $result->stdout;

        if (str_contains($output, 'NO_TOOL')) {
            return false;
        }

        if (preg_match('/EXIT:(\d+)/', $output, $m) === 1) {
            return (int) $m[1] === 1;
        }

        return false;
    }

    private function debianNeedsRestart(ServerConfig $config): bool
    {
        $result = $this->executor->executeSingle(
            $config,
            '[ -f /var/run/reboot-required ] && echo yes || echo no',
        );

        return trim((string) $result->stdout) === 'yes';
    }
}
