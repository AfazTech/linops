<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Support\CpuCalculator;
use PHPUnit\Framework\TestCase;

final class CpuCalculatorTest extends TestCase
{
    public function testNormalizedLoadWithFullCapacity(): void
    {
        self::assertSame(1.0, CpuCalculator::normalizeLoad(8.0, 8));
    }

    public function testNormalizedLoadWithHalfCapacity(): void
    {
        self::assertSame(0.5, CpuCalculator::normalizeLoad(4.0, 8));
    }

    public function testNormalizedLoadWithZeroCpuCount(): void
    {
        self::assertSame(0.0, CpuCalculator::normalizeLoad(5.0, 0));
    }

    public function testNormalizedLoadWithNegativeCpuCount(): void
    {
        self::assertSame(0.0, CpuCalculator::normalizeLoad(5.0, -1));
    }

    public function testUsageCalculationFromCounters(): void
    {
        $first = [
            'user' => 100.0, 'nice' => 0.0, 'system' => 50.0, 'idle' => 800.0,
            'iowait' => 10.0, 'irq' => 0.0, 'softirq' => 0.0, 'steal' => 0.0,
        ];
        $second = [
            'user' => 150.0, 'nice' => 0.0, 'system' => 70.0, 'idle' => 860.0,
            'iowait' => 12.0, 'irq' => 0.0, 'softirq' => 0.0, 'steal' => 0.0,
        ];

        $usage = CpuCalculator::calculateUsage($first, $second);

        self::assertNotNull($usage);
        self::assertArrayHasKey('usage', $usage);
        self::assertArrayHasKey('idle', $usage);
        self::assertGreaterThanOrEqual(0.0, $usage['usage']);
        self::assertLessThanOrEqual(100.0, $usage['usage']);
    }

    public function testUsageReturnsNullWhenCountersDoNotAdvance(): void
    {
        $same = [
            'user' => 100.0, 'nice' => 0.0, 'system' => 50.0, 'idle' => 800.0,
            'iowait' => 10.0, 'irq' => 0.0, 'softirq' => 0.0, 'steal' => 0.0,
        ];
        self::assertNull(CpuCalculator::calculateUsage($same, $same));
    }

    public function testLoadIsNotCpuUsage(): void
    {
        $normalized = CpuCalculator::normalizeLoad(8.0, 8);
        self::assertSame(1.0, $normalized);
        // This test documents: normalized load = 1.0 does not mean usage = 100%.
    }
}
