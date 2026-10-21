<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class DiskHardwareInfo
{
    /**
     * @param list<string> $sources
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $sizeBytes,
        public readonly ?string $type,
        public readonly ?string $model,
        public readonly ?string $serial,
        public readonly ?string $transport,
        public readonly ?bool $rotational,
        public readonly ?string $health,
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
