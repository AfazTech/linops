<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class FirewallInfo
{
    /**
     * @param array<string, string> $details
     */
    public function __construct(
        public readonly string $name,
        public readonly int $priority,
        public readonly bool $active,
        public readonly ?string $version,
        public readonly ?string $configPath,
        public readonly string $backend,
        public readonly array $details = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
