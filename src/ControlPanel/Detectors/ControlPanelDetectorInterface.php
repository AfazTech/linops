<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

use AfazTech\LinOps\Result\ControlPanelInfo;

interface ControlPanelDetectorInterface
{
    public function name(): string;

    public function category(): string;

    public function slug(): string;

    /** @return list<string> */
    public function getPaths(): array;

    /** @return list<string> */
    public function getServices(): array;

    /** @return list<string> */
    public function getPackages(): array;

    public function getVersionCommand(): ?string;

    /**
     * @param array{
     *   paths: array<string, bool>,
     *   services: array<string, bool>,
     *   packages: array<string, bool>,
     *   versions: array<string, string>,
     * } $evidence
     */
    public function evaluate(array $evidence): ?ControlPanelInfo;
}
