<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class CpuHardwareInfo
{
    /**
     * @param list<string> $flags
     * @param list<string> $sources
     */
    public function __construct(
        public readonly ?string $model,
        public readonly ?string $vendor,
        public readonly ?string $architecture,
        public readonly ?int $physicalCores,
        public readonly ?int $logicalThreads,
        public readonly ?int $sockets,
        public readonly ?float $maxMhz,
        public readonly ?float $minMhz,
        public readonly array $flags,
        public readonly array $sources,
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
