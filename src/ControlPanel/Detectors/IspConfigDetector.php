<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

final class IspConfigDetector extends AbstractDetector
{
    public function name(): string { return 'ISPConfig'; }
    public function category(): string { return 'open_source'; }

    public function getPaths(): array { return ['/usr/local/ispconfig', '/etc/ispconfig']; }
    public function getServices(): array { return ['ispconfig', 'ispconfig_panel']; }
    public function getPackages(): array { return ['ispconfig3']; }

    public function getVersionCommand(): ?string
    {
        return 'grep -oE "ISPC_APP_VERSION[^0-9]*[0-9]+[.][0-9]+([.][0-9]+)?" /usr/local/ispconfig/interface/lib/config.inc.php 2>/dev/null | grep -oE "[0-9]+[.][0-9]+([.][0-9]+)?" | head -1';
    }
}
