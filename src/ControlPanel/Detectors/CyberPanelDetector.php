<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class CyberPanelDetector extends AbstractDetector
{
    public function name(): string { return 'CyberPanel'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/usr/local/CyberCP', '/etc/cyberpanel']; }
    public function getServices(): array { return ['lscpd', 'cyberpanel']; }
    public function getPackages(): array { return ['cyberpanel']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /usr/local/CyberCP/version 2>/dev/null';
    }
}
