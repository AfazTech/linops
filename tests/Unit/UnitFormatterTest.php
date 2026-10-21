<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Support\UnitFormatter;
use PHPUnit\Framework\TestCase;

final class UnitFormatterTest extends TestCase
{
    public function testToBytesForKnownUnits(): void
    {
        self::assertSame(512, UnitFormatter::toBytes(512, 'B'));
        self::assertSame(1024, UnitFormatter::toBytes(1, 'KB'));
        self::assertSame(1048576, UnitFormatter::toBytes(1, 'MB'));
        self::assertSame(1073741824, UnitFormatter::toBytes(1, 'GB'));
        self::assertSame(1099511627776, UnitFormatter::toBytes(1, 'TB'));
    }

    public function testToBytesIsCaseInsensitive(): void
    {
        self::assertSame(1024, UnitFormatter::toBytes(1, 'kb'));
        self::assertSame(1024, UnitFormatter::toBytes(1, 'Kb'));
    }

    public function testToBytesWithUnknownUnitAssumesBytes(): void
    {
        self::assertSame(500, UnitFormatter::toBytes(500, 'XYZ'));
    }

    public function testPercentCalculation(): void
    {
        self::assertSame(50.0, UnitFormatter::percent(50, 100));
        self::assertSame(100.0, UnitFormatter::percent(100, 100));
        self::assertSame(0.0, UnitFormatter::percent(0, 100));
    }

    public function testPercentReturnsZeroForZeroTotal(): void
    {
        self::assertSame(0.0, UnitFormatter::percent(10, 0));
        self::assertSame(0.0, UnitFormatter::percent(0, 0));
    }

    public function testPercentRoundsToTwoDecimals(): void
    {
        self::assertSame(33.33, UnitFormatter::percent(1, 3));
    }
}
