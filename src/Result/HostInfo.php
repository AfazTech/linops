<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class HostInfo
{
    public function __construct(
        public readonly string $hostname,
        public readonly string $operatingSystem,
        public readonly string $distributionId,
        public readonly string $distributionVersion,
        public readonly string $kernelVersion,
        public readonly string $architecture,
        public readonly float $uptimeSeconds,
        public readonly int $logicalCpuCount,
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
