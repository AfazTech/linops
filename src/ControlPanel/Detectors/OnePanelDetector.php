<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class OnePanelDetector extends AbstractDetector
{
    public function name(): string { return '1Panel'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/opt/1panel', '/usr/local/bin/1pctl']; }
    public function getServices(): array { return ['1panel', '1panel-core']; }
    public function getPackages(): array { return ['1panel']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /opt/1panel/version 2>/dev/null';
    }
}
