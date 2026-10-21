<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class SentoraDetector extends AbstractDetector
{
    public function name(): string { return 'Sentora'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/etc/sentora', '/var/sentora']; }
    public function getServices(): array { return ['sentora']; }
    public function getPackages(): array { return ['sentora']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /etc/sentora/configs/sentora-install/version 2>/dev/null';
    }
}
