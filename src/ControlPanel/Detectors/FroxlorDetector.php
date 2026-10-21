<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class FroxlorDetector extends AbstractDetector
{
    public function name(): string { return 'Froxlor'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/var/www/froxlor', '/etc/froxlor']; }
    public function getServices(): array { return ['froxlor']; }
    public function getPackages(): array { return ['froxlor']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /var/www/froxlor/version.txt 2>/dev/null';
    }
}
