<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class EnhanceDetector extends AbstractDetector
{
    public function name(): string { return 'Enhance'; }
    public function category(): string { return 'commercial'; }

    public function getPaths(): array { return ['/opt/enhance', '/usr/local/enhance']; }
    public function getServices(): array { return ['enhance', 'enhance-cp']; }
    public function getPackages(): array { return ['enhance']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /opt/enhance/version 2>/dev/null';
    }
}
