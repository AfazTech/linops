<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class CpanelDetector extends AbstractDetector
{
    public function name(): string { return 'cPanel / WHM'; }
    public function category(): string { return 'commercial'; }

    public function getPaths(): array { return ['/usr/local/cpanel', '/etc/cpanel']; }
    public function getServices(): array { return ['cpanel', 'cphulkd', 'cpdavd']; }
    public function getPackages(): array { return ['cpanel', 'cpanel-libs']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /usr/local/cpanel/version 2>/dev/null';
    }
}
