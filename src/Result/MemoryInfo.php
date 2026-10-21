<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class MemoryInfo
{
    public function __construct(
        public readonly int $totalBytes,
        public readonly int $usedBytes,
        public readonly int $availableBytes,
        public readonly int $freeBytes,
        public readonly int $cachedBytes,
        public readonly int $bufferedBytes,
        public readonly float $usagePercent,
        public readonly int $swapTotalBytes,
        public readonly int $swapUsedBytes,
        public readonly int $swapAvailableBytes,
        public readonly float $swapUsagePercent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
