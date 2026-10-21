<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Firewall\Detectors\CsfDetector;
use AfazTech\LinOps\Firewall\Detectors\FirewallDetectorInterface;
use AfazTech\LinOps\Firewall\Detectors\FirewalldDetector;
use AfazTech\LinOps\Firewall\Detectors\IptablesDetector;
use AfazTech\LinOps\Firewall\Detectors\NftablesDetector;
use AfazTech\LinOps\Firewall\Detectors\UfwDetector;
use AfazTech\LinOps\Result\FirewallInfo;
use AfazTech\LinOps\ServerConfig;

final class FirewallDetector
{
    /** @var list<FirewallDetectorInterface> */
    private array $detectors;

    /**
     * @param list<FirewallDetectorInterface>|null $detectors
     */
    public function __construct(
        private readonly CommandExecutor $executor,
        ?array $detectors = null,
    ) {
        $this->detectors = $detectors ?? [
            new CsfDetector(),
            new FirewalldDetector(),
            new UfwDetector(),
            new NftablesDetector(),
            new IptablesDetector(),
        ];
    }

    /**
     * Returns all active firewalls sorted by descending precedence
     * (lower priority value first). The first element is the controlling
     * firewall.
     *
     * @return list<FirewallInfo>
     */
    public function detect(ServerConfig $config): array
    {
        $evidence = $this->gatherEvidence($config);

        $results = [];
        foreach ($this->detectors as $detector) {
            try {
                $info = $detector->evaluate($evidence[$detector->name()] ?? []);
                if ($info !== null && $info->active) {
                    $results[] = $info;
                }
            } catch (\Throwable) {
                // A broken detector must not abort detection of others.
            }
        }

        usort(
            $results,
            static fn (FirewallInfo $a, FirewallInfo $b): int => $a->priority <=> $b->priority,
        );

        return $results;
    }

    public function getActive(ServerConfig $config): ?FirewallInfo
    {
        return $this->detect($config)[0] ?? null;
    }

    /**
     * Runs all probes in a single SSH call and returns a map of
     * [detectorName => [probeName => output]].
     *
     * @return array<string, array<string, string>>
     */
    private function gatherEvidence(ServerConfig $config): array
    {
        $script = "set +e\n";
        $sectionMap = [];

        foreach ($this->detectors as $detector) {
            foreach ($detector->getProbes() as $probeName => $command) {
                $key = $detector->name() . ':' . $probeName;
                $sectionMap[$key] = [$detector->name(), $probeName];
                $script .= sprintf("echo '===%s==='\n", $key);
                $script .= sprintf("(%s) 2>&1 || true\n", $command);
            }
        }
        $script .= "echo '===END==='\n";

        $result = $this->executor->executeSingle($config, $script, 60.0);

        $sections = [];
        $current = null;

        foreach (explode("\n", (string) $result->stdout) as $line) {
            if (preg_match('/^===(\w+):([\w-]+)===$/', $line, $m) === 1) {
                $current = $m[1] . ':' . $m[2];
                $sections[$current] = '';
                continue;
            }
            if ($line === '===END===') {
                $current = null;
                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        $grouped = [];
        foreach ($sectionMap as $key => [$detectorName, $probeName]) {
            $grouped[$detectorName][$probeName] = $sections[$key] ?? '';
        }

        return $grouped;
    }
}
