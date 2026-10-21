<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class BiosHardwareInfo
{
    public function __construct(
        public readonly ?string $vendor,
        public readonly ?string $version,
        public readonly ?string $releaseDate,
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
