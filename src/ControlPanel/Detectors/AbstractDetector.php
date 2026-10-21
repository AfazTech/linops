<?php

declare(strict_types=1);

namespace AfazTech\LinOps\ControlPanel\Detectors;

use AfazTech\LinOps\Result\ControlPanelInfo;

abstract class AbstractDetector implements ControlPanelDetectorInterface
{
    private ?string $cachedSlug = null;

    public function slug(): string
    {
        return $this->cachedSlug ??= strtolower((new \ReflectionClass($this))->getShortName());
    }

    /** @return list<string> */
    public function getPaths(): array
    {
        return [];
    }

    /** @return list<string> */
    public function getServices(): array
    {
        return [];
    }

    /** @return list<string> */
    public function getPackages(): array
    {
        return [];
    }

    public function getVersionCommand(): ?string
    {
        return null;
    }

    /**
     * @param array{
     *   paths: array<string, bool>,
     *   services: array<string, bool>,
     *   packages: array<string, bool>,
     *   versions: array<string, string>,
     * } $evidence
     */
    public function evaluate(array $evidence): ?ControlPanelInfo
    {
        $used = [];
        $confidence = 0.0;
        $installationPath = null;
        $activeServices = [];

        foreach ($this->getPaths() as $path) {
            if (!empty($evidence['paths'][$path])) {
                $used[] = 'PATH:' . $path;
                $confidence += 0.5;
                if ($installationPath === null) {
                    $installationPath = $path;
                }
            }
        }

        foreach ($this->getPackages() as $pkg) {
            if (!empty($evidence['packages'][$pkg])) {
                $used[] = 'PACKAGE:' . $pkg;
                $confidence += 0.3;
            }
        }

        foreach ($this->getServices() as $svc) {
            if (!empty($evidence['services'][$svc])) {
                $used[] = 'SERVICE:' . $svc;
                $confidence += 0.2;
                $activeServices[] = $svc;
            }
        }

        if ($used === []) {
            return null;
        }

        $version = $evidence['versions'][$this->slug()] ?? null;
        if ($version === '') {
            $version = null;
        }

        return new ControlPanelInfo(
            name: $this->name(),
            category: $this->category(),
            version: $version,
            installationPath: $installationPath,
            services: $activeServices,
            confidence: min(1.0, $confidence),
            evidence: $used,
            status: $confidence >= 0.5 ? 'detected' : 'inconclusive',
        );
    }
}
