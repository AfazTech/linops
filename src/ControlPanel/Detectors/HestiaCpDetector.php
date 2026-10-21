<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class HestiaCpDetector extends AbstractDetector
{
    public function name(): string { return 'HestiaCP'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/usr/local/hestia', '/etc/hestia']; }
    public function getServices(): array { return ['hestia', 'hestia-nginx', 'hestia-php']; }
    public function getPackages(): array { return ['hestia', 'hestia-nginx']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /usr/local/hestia/VERSION 2>/dev/null';
    }
}
