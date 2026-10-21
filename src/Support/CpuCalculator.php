<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Support;

final class CpuCalculator
{
    /**
     * @param array{user: float, nice: float, system: float, idle: float, iowait: float, irq: float, softirq: float, steal: float} $first
     * @param array{user: float, nice: float, system: float, idle: float, iowait: float, irq: float, softirq: float, steal: float} $second
     * @return array{usage: float, user: float, system: float, iowait: float, idle: float}|null
     */
    public static function calculateUsage(array $first, array $second): ?array
    {
        $totalFirst = array_sum($first);
        $totalSecond = array_sum($second);
        $totalDelta = $totalSecond - $totalFirst;

        if ($totalDelta <= 0) {
            return null;
        }

        $idleDelta = $second['idle'] - $first['idle'];
        $userDelta = ($second['user'] + $second['nice']) - ($first['user'] + $first['nice']);
        $systemDelta = ($second['system'] + $second['irq'] + $second['softirq'])
            - ($first['system'] + $first['irq'] + $first['softirq']);
        $iowaitDelta = $second['iowait'] - $first['iowait'];

        return [
            'usage' => round((1 - $idleDelta / $totalDelta) * 100, 2),
            'user' => round(($userDelta / $totalDelta) * 100, 2),
            'system' => round(($systemDelta / $totalDelta) * 100, 2),
            'iowait' => round(($iowaitDelta / $totalDelta) * 100, 2),
            'idle' => round(($idleDelta / $totalDelta) * 100, 2),
        ];
    }

    public static function normalizeLoad(float $load, int $logicalCpuCount): float
    {
        if ($logicalCpuCount <= 0) {
            return 0.0;
        }

        return round($load / $logicalCpuCount, 4);
    }
}
