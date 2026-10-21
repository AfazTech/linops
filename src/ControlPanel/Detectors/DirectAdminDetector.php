<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class DirectAdminDetector extends AbstractDetector
{
    public function name(): string { return 'DirectAdmin'; }
    public function category(): string { return 'commercial'; }

    public function getPaths(): array { return ['/usr/local/directadmin', '/etc/directadmin']; }
    public function getServices(): array { return ['directadmin', 'da-popb4smtp']; }
    public function getPackages(): array { return ['directadmin']; }

    public function getVersionCommand(): ?string
    {
        return 'grep "^directadmin:" /usr/local/directadmin/custombuild/versions.txt 2>/dev/null | head -1 | cut -d: -f2';
    }
}
