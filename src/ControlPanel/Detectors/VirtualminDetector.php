<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class VirtualminDetector extends AbstractDetector
{
    public function name(): string { return 'Virtualmin / Webmin'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/etc/webmin', '/usr/libexec/webmin', '/etc/virtualmin']; }
    public function getServices(): array { return ['webmin', 'virtualmin']; }
    public function getPackages(): array { return ['webmin', 'virtualmin-base']; }

    public function getVersionCommand(): ?string
    {
        return 'cat /etc/webmin/version 2>/dev/null';
    }
}
