<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

use AfazTech\LinOps\Result\FirewallInfo;

/**
 * Raw iptables. Lowest priority because higher-level tools (CSF, firewalld,
 * ufw) also program iptables. Only reported as active when its ruleset
 * contains more than the default policy lines.
 */
final class IptablesDetector extends AbstractFirewallDetector
{
    public function name(): string { return 'iptables'; }
    public function priority(): int { return 50; }
    protected function binaryName(): string { return 'iptables'; }

    protected function activeProbe(): string
    {
        return 'iptables -S 2>/dev/null | grep -vc "^-P "';
    }

    protected function versionProbe(): string
    {
        return 'iptables --version 2>/dev/null | head -1';
    }

    protected function configProbe(): string
    {
        return 'ls -d /etc/iptables /etc/sysconfig/iptables 2>/dev/null';
    }

    public function evaluate(array $evidence): ?FirewallInfo
    {
        if (!$this->isBinaryAvailable($evidence)) {
            return null;
        }

        $ruleCount = (int) trim($evidence['active'] ?? '0');

        return new FirewallInfo(
            name: 'iptables',
            priority: $this->priority(),
            active: $ruleCount > 0,
            version: $this->version($evidence),
            configPath: $this->configPath($evidence),
            backend: 'iptables',
        );
    }
}
