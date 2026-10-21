<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

abstract class AbstractFirewallDetector implements FirewallDetectorInterface
{
    public function getProbes(): array
    {
        return [
            'bin' => 'command -v ' . escapeshellarg($this->binaryName()),
            'active' => $this->activeProbe(),
            'version' => $this->versionProbe(),
            'config' => $this->configProbe(),
        ];
    }

    abstract protected function binaryName(): string;

    protected function activeProbe(): string
    {
        return 'true';
    }

    protected function versionProbe(): string
    {
        return 'true';
    }

    protected function configProbe(): string
    {
        return 'true';
    }

    protected function isBinaryAvailable(array $evidence): bool
    {
        return trim($evidence['bin'] ?? '') !== '';
    }

    protected function isActive(array $evidence): bool
    {
        return str_contains(strtolower($evidence['active'] ?? ''), 'active');
    }

    protected function version(array $evidence): ?string
    {
        $v = trim($evidence['version'] ?? '');
        return $v !== '' ? $v : null;
    }

    protected function configPath(array $evidence): ?string
    {
        $c = trim($evidence['config'] ?? '');
        return $c !== '' ? $c : null;
    }
}
