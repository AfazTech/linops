<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

use AfazTech\LinOps\Result\FirewallInfo;

/**
 * Uncomplicated Firewall (UFW). Manages iptables, so it takes precedence
 * over raw iptables.
 */
final class UfwDetector extends AbstractFirewallDetector
{
    public function name(): string { return 'ufw'; }
    public function priority(): int { return 30; }
    protected function binaryName(): string { return 'ufw'; }

    protected function activeProbe(): string
    {
        return 'ufw status 2>/dev/null | head -1';
    }

    protected function versionProbe(): string
    {
        return 'ufw version 2>/dev/null | head -1';
    }

    protected function configProbe(): string
    {
        return 'ls -d /etc/ufw 2>/dev/null';
    }

    public function evaluate(array $evidence): ?FirewallInfo
    {
        if (!$this->isBinaryAvailable($evidence)) {
            return null;
        }

        // `ufw status` prints "Status: active" or "Status: inactive".
        $statusLine = trim($evidence['active'] ?? '');

        return new FirewallInfo(
            name: 'ufw',
            priority: $this->priority(),
            active: str_contains(strtolower($statusLine), 'active')
                && !str_contains(strtolower($statusLine), 'inactive'),
            version: $this->version($evidence),
            configPath: $this->configPath($evidence),
            backend: 'iptables',
        );
    }
}
