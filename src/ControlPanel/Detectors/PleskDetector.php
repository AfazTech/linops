<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class PleskDetector extends AbstractDetector
{
    public function name(): string { return 'Plesk'; }
    public function category(): string { return 'commercial'; }

    public function getPaths(): array { return ['/usr/local/psa', '/opt/psa']; }
    public function getServices(): array { return ['psa', 'sw-cp-server', 'plesk-php-fpm']; }
    public function getPackages(): array { return ['psa', 'plesk-core']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /usr/local/psa/version 2>/dev/null | head -1 | cut -d" " -f1';
    }
}
