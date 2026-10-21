<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

/**
 * Hardware inventory with explicit availability tracking.
 *
 * Any sub-object may be null if the underlying source failed. Fields that
 * were attempted but unavailable are listed in $unavailable with a reason.
 * The caller is expected to format bytes and other raw values.
 */
final class HardwareInfo
{
    /**
     * @param list<DiskHardwareInfo> $disks
     * @param array<string, bool> $sources
     * @param array<string, string> $unavailable
     */
    public function __construct(
        public readonly ?CpuHardwareInfo $cpu,
        public readonly ?MemoryHardwareInfo $memory,
        public readonly array $disks,
        public readonly ?MotherboardHardwareInfo $motherboard,
        public readonly ?BiosHardwareInfo $bios,
        public readonly array $sources,
        public readonly array $unavailable,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cpu' => $this->cpu?->toArray(),
            'memory' => $this->memory?->toArray(),
            'disks' => array_map(
                static fn (DiskHardwareInfo $d): array => $d->toArray(),
                $this->disks,
            ),
            'motherboard' => $this->motherboard?->toArray(),
            'bios' => $this->bios?->toArray(),
            'sources' => $this->sources,
            'unavailable' => $this->unavailable,
        ];
    }
}
