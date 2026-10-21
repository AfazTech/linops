<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

use AfazTech\LinOps\Result\FirewallInfo;

/**
 * firewalld. Manages iptables/nftables under the hood, so it takes
 * precedence over raw iptables and nftables.
 */
final class FirewalldDetector extends AbstractFirewallDetector
{
    public function name(): string { return 'firewalld'; }
    public function priority(): int { return 20; }
    protected function binaryName(): string { return 'firewall-cmd'; }

    protected function activeProbe(): string
    {
        return 'systemctl is-active firewalld 2>/dev/null';
    }

    protected function versionProbe(): string
    {
        return 'firewall-cmd --version 2>/dev/null | head -1';
    }

    protected function configProbe(): string
    {
        return 'ls -d /etc/firewalld 2>/dev/null';
    }

    public function evaluate(array $evidence): ?FirewallInfo
    {
        if (!$this->isBinaryAvailable($evidence)) {
            return null;
        }

        return new FirewallInfo(
            name: 'firewalld',
            priority: $this->priority(),
            active: $this->isActive($evidence),
            version: $this->version($evidence),
            configPath: $this->configPath($evidence),
            backend: 'iptables',
        );
    }
}
