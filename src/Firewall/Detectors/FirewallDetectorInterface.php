<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Firewall\Detectors;

use AfazTech\LinOps\Result\FirewallInfo;

interface FirewallDetectorInterface
{
    public function name(): string;

    /**
     * Lower value = higher priority when multiple firewalls are active.
     * The controlling firewall (e.g. CSF) is returned first by the
     * orchestrator.
     */
    public function priority(): int;

    /**
     * @return array<string, string> Map of probe name to shell command.
     */
    public function getProbes(): array;

    /**
     * @param array<string, string> $evidence Map of probe name to output.
     */
    public function evaluate(array $evidence): ?FirewallInfo;
}
