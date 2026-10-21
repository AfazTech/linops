<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Support;

final class UnitFormatter
{
    public static function toBytes(int|float $value, string $unit): int
    {
        $unit = strtoupper(trim($unit));
        $multipliers = [
            'B' => 1,
            'K' => 1024,
            'KB' => 1024,
            'M' => 1024 ** 2,
            'MB' => 1024 ** 2,
            'G' => 1024 ** 3,
            'GB' => 1024 ** 3,
            'T' => 1024 ** 4,
            'TB' => 1024 ** 4,
        ];

        if (!isset($multipliers[$unit])) {
            return (int) round($value);
        }

        return (int) round($value * $multipliers[$unit]);
    }

    public static function percent(int|float $part, int|float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }
}
