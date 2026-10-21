<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\ControlPanel\Detectors\CpanelDetector;
use AfazTech\LinOps\ControlPanel\Detectors\HestiaCpDetector;
use AfazTech\LinOps\Result\ControlPanelInfo;
use PHPUnit\Framework\TestCase;

final class ControlPanelDetectorTest extends TestCase
{
    public function testPanelInfoExposesEvidenceAndConfidence(): void
    {
        $info = new ControlPanelInfo(
            name: 'cPanel / WHM',
            category: 'commercial',
            version: null,
            installationPath: '/usr/local/cpanel',
            services: ['cpanel'],
            confidence: 0.8,
            evidence: ['PATH:/usr/local/cpanel', 'SERVICE:cpanel'],
            status: 'detected',
        );

        self::assertSame('cPanel / WHM', $info->name);
        self::assertSame(0.8, $info->confidence);
        self::assertSame('detected', $info->status);
        self::assertContains('PATH:/usr/local/cpanel', $info->evidence);
    }

    public function testCpanelDetectorMetadata(): void
    {
        $detector = new CpanelDetector();
        self::assertSame('cPanel / WHM', $detector->name());
        self::assertSame('commercial', $detector->category());
    }

    public function testHestiaCpDetectorMetadata(): void
    {
        $detector = new HestiaCpDetector();
        self::assertSame('HestiaCP', $detector->name());
        self::assertSame('open_source', $detector->category());
    }

    public function testInconclusiveResultIsExplicit(): void
    {
        $info = new ControlPanelInfo(
            name: 'Unknown',
            category: 'unknown',
            version: null,
            installationPath: null,
            services: [],
            confidence: 0.2,
            evidence: ['PATH:/usr/local/unknown'],
            status: 'inconclusive',
        );

        self::assertSame('inconclusive', $info->status);
        self::assertLessThan(0.5, $info->confidence);
    }
}
