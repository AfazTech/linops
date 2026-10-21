<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

use AfazTech\LinOps\Result\FirewallInfo;

/**
 * ConfigServer Firewall (CSF). Highest priority because CSF manages
 * iptables rules directly; when CSF is present it is the controlling
 * firewall even though iptables is also installed.
 */
final class CsfDetector extends AbstractFirewallDetector
{
    public function name(): string { return 'CSF'; }
    public function priority(): int { return 10; }
    protected function binaryName(): string { return 'csf'; }

    protected function activeProbe(): string
    {
        return 'systemctl is-active csf lfd 2>/dev/null; pgrep -x lfd >/dev/null && echo "lfd-running"';
    }

    protected function versionProbe(): string
    {
        return 'csf -v 2>/dev/null | head -1';
    }

    protected function configProbe(): string
    {
        return 'ls -d /etc/csf 2>/dev/null';
    }

    public function evaluate(array $evidence): ?FirewallInfo
    {
        if (!$this->isBinaryAvailable($evidence)) {
            return null;
        }

        return new FirewallInfo(
            name: 'CSF',
            priority: $this->priority(),
            active: $this->isActive($evidence),
            version: $this->version($evidence),
            configPath: $this->configPath($evidence),
            backend: 'iptables',
        );
    }
}
