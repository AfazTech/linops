<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class WebuzoDetector extends AbstractDetector
{
    public function name(): string { return 'Webuzo'; }
    public function category(): string { return 'commercial'; }

    public function getPaths(): array { return ['/usr/local/emps', '/usr/local/webuzo']; }
    public function getServices(): array { return ['webuzo', 'emps']; }
    public function getPackages(): array { return ['webuzo']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /usr/local/webuzo/version 2>/dev/null';
    }
}
