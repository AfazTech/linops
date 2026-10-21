<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class DiskInfo
{
    public function __construct(
        public readonly string $filesystem,
        public readonly string $mountPoint,
        public readonly string $type,
        public readonly ?int $totalBytes,
        public readonly ?int $usedBytes,
        public readonly ?int $availableBytes,
        public readonly ?float $usagePercent,
        public readonly ?int $inodeTotal,
        public readonly ?int $inodeUsed,
        public readonly ?int $inodeAvailable,
        public readonly ?float $inodeUsagePercent,
        public readonly bool $available,
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
