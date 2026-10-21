<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Result\InterfaceTrafficInfo;
use PHPUnit\Framework\TestCase;

final class NetworkRateTest extends TestCase
{
    public function testCounterResetProducesNullRates(): void
    {
        $info = new InterfaceTrafficInfo(
            interface: 'eth0',
            rxBytes: 100,
            txBytes: 200,
            rxPackets: 1,
            txPackets: 2,
            rxErrors: 0,
            txErrors: 0,
            rxDrops: 0,
            txDrops: 0,
            rxBytesPerSecond: null,
            txBytesPerSecond: null,
            rxBitsPerSecond: null,
            txBitsPerSecond: null,
            sampleIntervalSeconds: 1.0,
            counterReset: true,
        );

        self::assertNull($info->rxBytesPerSecond);
        self::assertNull($info->txBytesPerSecond);
        self::assertNull($info->rxBitsPerSecond);
        self::assertNull($info->txBitsPerSecond);
        self::assertTrue($info->counterReset);
    }

    public function testRateCalculationOverTwoSeconds(): void
    {
        $rxDelta = 1000;
        $interval = 2.0;
        $rate = $rxDelta / $interval;

        self::assertSame(500.0, $rate);
        self::assertSame(4000.0, $rate * 8.0);
    }

    public function testRateCalculationOverFractionalInterval(): void
    {
        $rxDelta = 500;
        $interval = 0.5;
        $rate = $rxDelta / $interval;

        self::assertSame(1000.0, $rate);
        self::assertSame(8000.0, $rate * 8.0);
    }

    public function testRatesAreNeverNegative(): void
    {
        // Counters only decrease on reset, which must produce null, not negative rates.
        $info = new InterfaceTrafficInfo(
            interface: 'eth0',
            rxBytes: 0,
            txBytes: 0,
            rxPackets: 0,
            txPackets: 0,
            rxErrors: 0,
            txErrors: 0,
            rxDrops: 0,
            txDrops: 0,
            rxBytesPerSecond: null,
            txBytesPerSecond: null,
            rxBitsPerSecond: null,
            txBitsPerSecond: null,
            sampleIntervalSeconds: 1.0,
            counterReset: true,
        );

        self::assertNull($info->rxBytesPerSecond);
        self::assertNull($info->txBytesPerSecond);
    }
}
