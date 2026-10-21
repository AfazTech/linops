<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

use AfazTech\LinOps\Result\FirewallInfo;

/**
 * Native nftables. Independent of iptables (although iptables-nft shims
 * exist), so it takes precedence over raw iptables.
 */
final class NftablesDetector extends AbstractFirewallDetector
{
    public function name(): string { return 'nftables'; }
    public function priority(): int { return 40; }
    protected function binaryName(): string { return 'nft'; }

    protected function activeProbe(): string
    {
        return 'systemctl is-active nftables 2>/dev/null; nft list ruleset 2>/dev/null | head -1';
    }

    protected function versionProbe(): string
    {
        return 'nft --version 2>/dev/null | head -1';
    }

    protected function configProbe(): string
    {
        return 'ls -d /etc/nftables.d /etc/nftables.conf 2>/dev/null';
    }

    public function evaluate(array $evidence): ?FirewallInfo
    {
        if (!$this->isBinaryAvailable($evidence)) {
            return null;
        }

        return new FirewallInfo(
            name: 'nftables',
            priority: $this->priority(),
            active: $this->isActive($evidence),
            version: $this->version($evidence),
            configPath: $this->configPath($evidence),
            backend: 'nftables',
        );
    }
}
