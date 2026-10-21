<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class InterfaceTrafficInfo
{
    public function __construct(
        public readonly string $interface,
        public readonly int $rxBytes,
        public readonly int $txBytes,
        public readonly int $rxPackets,
        public readonly int $txPackets,
        public readonly int $rxErrors,
        public readonly int $txErrors,
        public readonly int $rxDrops,
        public readonly int $txDrops,
        public readonly ?float $rxBytesPerSecond,
        public readonly ?float $txBytesPerSecond,
        public readonly ?float $rxBitsPerSecond,
        public readonly ?float $txBitsPerSecond,
        public readonly ?float $sampleIntervalSeconds,
        public readonly bool $counterReset,
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
