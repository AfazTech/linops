<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class AaPanelDetector extends AbstractDetector
{
    public function name(): string { return 'aaPanel'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/www/server/panel']; }
    public function getServices(): array { return ['bt', 'aaPanel']; }
    public function getPackages(): array { return ['aapanel']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /www/server/panel/data/version.pl 2>/dev/null';
    }
}
