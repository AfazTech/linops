<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class MemoryModuleInfo
{
    public function __construct(
        public readonly ?string $locator,
        public readonly ?int $sizeBytes,
        public readonly ?string $type,
        public readonly ?int $speedMts,
        public readonly ?string $manufacturer,
        public readonly ?string $partNumber,
        public readonly ?string $serial,
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
