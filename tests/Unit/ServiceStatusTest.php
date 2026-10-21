<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Result\ServiceStatus;
use PHPUnit\Framework\TestCase;

final class ServiceStatusTest extends TestCase
{
    public function testUnsupportedServiceManagerIsExplicit(): void
    {
        $status = new ServiceStatus(
            name: 'systemd',
            description: null,
            loadState: null,
            activeState: null,
            subState: null,
            enabled: null,
            mainPid: null,
            supported: false,
            error: 'systemctl unavailable',
        );

        self::assertFalse($status->supported);
        self::assertSame('systemctl unavailable', $status->error);
    }

    public function testActiveServiceStatus(): void
    {
        $status = new ServiceStatus(
            name: 'nginx.service',
            description: 'nginx web server',
            loadState: 'loaded',
            activeState: 'active',
            subState: 'running',
            enabled: true,
            mainPid: 1234,
            supported: true,
            error: null,
        );

        self::assertTrue($status->supported);
        self::assertTrue($status->enabled);
        self::assertSame(1234, $status->mainPid);
        self::assertNull($status->error);
    }
}
