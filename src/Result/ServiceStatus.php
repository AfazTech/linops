<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class ServiceStatus
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $loadState,
        public readonly ?string $activeState,
        public readonly ?string $subState,
        public readonly ?bool $enabled,
        public readonly ?int $mainPid,
        public readonly bool $supported,
        public readonly ?string $error,
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
