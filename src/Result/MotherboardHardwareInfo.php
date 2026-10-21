<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class MotherboardHardwareInfo
{
    public function __construct(
        public readonly ?string $manufacturer,
        public readonly ?string $productName,
        public readonly ?string $version,
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
