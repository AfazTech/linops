<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\ControlPanel\Detectors\AaPanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\CloudPanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\ControlPanelDetectorInterface;
use AfazTech\LinOps\ControlPanel\Detectors\CpanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\CyberPanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\DirectAdminDetector;
use AfazTech\LinOps\ControlPanel\Detectors\EnhanceDetector;
use AfazTech\LinOps\ControlPanel\Detectors\FastPanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\FroxlorDetector;
use AfazTech\LinOps\ControlPanel\Detectors\HestiaCpDetector;
use AfazTech\LinOps\ControlPanel\Detectors\IspConfigDetector;
use AfazTech\LinOps\ControlPanel\Detectors\OnePanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\PleskDetector;
use AfazTech\LinOps\ControlPanel\Detectors\SentoraDetector;
use AfazTech\LinOps\ControlPanel\Detectors\VestaCpDetector;
use AfazTech\LinOps\ControlPanel\Detectors\VirtualminDetector;
use AfazTech\LinOps\ControlPanel\Detectors\WebuzoDetector;
use AfazTech\LinOps\Result\ControlPanelInfo;
use AfazTech\LinOps\ServerConfig;

final class ControlPanelDetector
{
    /** @var list<ControlPanelDetectorInterface> */
    private array $detectors;

    /**
     * @param list<ControlPanelDetectorInterface>|null $detectors
     */
    public function __construct(
        private readonly CommandExecutor $executor,
        ?array $detectors = null,
    ) {
        $this->detectors = $detectors ?? [
            new CpanelDetector(),
            new PleskDetector(),
            new DirectAdminDetector(),
            new WebuzoDetector(),
            new EnhanceDetector(),
            new HestiaCpDetector(),
            new IspConfigDetector(),
            new VirtualminDetector(),
            new FroxlorDetector(),
            new CyberPanelDetector(),
            new AaPanelDetector(),
            new CloudPanelDetector(),
            new FastPanelDetector(),
            new OnePanelDetector(),
            new VestaCpDetector(),
            new SentoraDetector(),
        ];
    }

    /**
     * Returns only panels with status="detected", sorted by descending confidence.
     * All detectors run against a single batched evidence set (one SSH call).
     *
     * @return list<ControlPanelInfo>
     */
    public function detect(ServerConfig $config): array
    {
        $evidence = $this->gatherEvidence($config);
        $results = [];

        foreach ($this->detectors as $detector) {
            try {
                $info = $detector->evaluate($evidence);
                if ($info !== null && $info->status === 'detected') {
                    $results[] = $info;
                }
            } catch (\Throwable) {
                // A broken detector must not abort detection of other panels.
            }
        }

        usort(
            $results,
            static fn (ControlPanelInfo $a, ControlPanelInfo $b): int => $b->confidence <=> $a->confidence,
        );

        return $results;
    }

    /**
     * @return array{
     *   paths: array<string, bool>,
     *   services: array<string, bool>,
     *   packages: array<string, bool>,
     *   versions: array<string, string>,
     * }
     */
    private function gatherEvidence(ServerConfig $config): array
    {
        $paths = [];
        $services = [];
        $packages = [];
        $versionCmds = [];

        foreach ($this->detectors as $detector) {
            foreach ($detector->getPaths() as $p) {
                $paths[$p] = true;
            }
            foreach ($detector->getServices() as $s) {
                $services[$s] = true;
            }
            foreach ($detector->getPackages() as $pkg) {
                $packages[$pkg] = true;
            }
            $vc = $detector->getVersionCommand();
            if ($vc !== null) {
                $versionCmds[$detector->slug()] = $vc;
            }
        }

        $script = [];
        $script[] = 'set +e';

        if ($paths !== []) {
            $script[] = 'for p in ' . implode(' ', array_map('escapeshellarg', array_keys($paths))) . '; do';
            $script[] = '  [ -e "$p" ] && echo "PATH:$p" || true';
            $script[] = 'done';
        }

        if ($services !== []) {
            $script[] = 'SERVICES=$(systemctl list-unit-files --no-legend --no-pager --type=service --all 2>/dev/null | awk \'{print $1}\' | sed \'s/\\.service$//\')';
            $script[] = 'for s in ' . implode(' ', array_map('escapeshellarg', array_keys($services))) . '; do';
            $script[] = '  echo "$SERVICES" | grep -qxF "$s" && echo "SERVICE:$s" || true';
            $script[] = 'done';
        }

        if ($packages !== []) {
            $script[] = 'PKGS=$( { rpm -qa 2>/dev/null; dpkg-query -W -f=\'${Package}\\n\' 2>/dev/null; } | sort -u )';
            $script[] = 'for p in ' . implode(' ', array_map('escapeshellarg', array_keys($packages))) . '; do';
            $script[] = '  echo "$PKGS" | grep -qxF "$p" && echo "PKG:$p" || true';
            $script[] = 'done';
        }

        foreach ($versionCmds as $slug => $cmd) {
            $script[] = sprintf('v=$( { %s ; } 2>/dev/null | head -1 )', $cmd);
            $script[] = sprintf('printf \'VER:%%s:%%s\\n\' %s "$v"', escapeshellarg($slug));
        }

        $result = $this->executor->executeSingle($config, implode("\n", $script), 60.0);

        $evidence = [
            'paths' => [],
            'services' => [],
            'packages' => [],
            'versions' => [],
        ];

        foreach (explode("\n", (string) $result->stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'PATH:')) {
                $evidence['paths'][substr($line, 5)] = true;
            } elseif (str_starts_with($line, 'SERVICE:')) {
                $evidence['services'][substr($line, 8)] = true;
            } elseif (str_starts_with($line, 'PKG:')) {
                $evidence['packages'][substr($line, 4)] = true;
            } elseif (str_starts_with($line, 'VER:')) {
                $rest = substr($line, 4);
                $pos = strpos($rest, ':');
                if ($pos !== false) {
                    $slug = substr($rest, 0, $pos);
                    $evidence['versions'][$slug] = trim(substr($rest, $pos + 1));
                }
            }
        }

        return $evidence;
    }
}
