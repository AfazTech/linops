<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class CloudPanelDetector extends AbstractDetector
{
    public function name(): string { return 'CloudPanel'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/home/clp', '/etc/cloudpanel']; }
    public function getServices(): array { return ['cloudpanel', 'clp-nginx']; }
    public function getPackages(): array { return ['cloudpanel']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /home/clp/htdocs/app/version.txt 2>/dev/null';
    }
}
