<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class FastPanelDetector extends AbstractDetector
{
    public function name(): string { return 'FASTPANEL'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/usr/local/fastpanel2', '/etc/fastpanel2']; }
    public function getServices(): array { return ['fastpanel2', 'fastpanel']; }
    public function getPackages(): array { return ['fastpanel2']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /usr/local/fastpanel2/version 2>/dev/null';
    }
}
