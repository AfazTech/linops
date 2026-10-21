<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class VestaCpDetector extends AbstractDetector
{
    public function name(): string { return 'VestaCP'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/usr/local/vesta', '/etc/vesta']; }
    public function getServices(): array { return ['vesta', 'vesta-nginx']; }
    public function getPackages(): array { return ['vesta']; }

    public function getVersionCommand(): ?string
    {
        return 'grep "^VERSION=" /usr/local/vesta/conf/vesta.conf 2>/dev/null | cut -d"\'" -f2';
    }
}
