<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class RouteInfo
{
    public function __construct(
        public readonly string $family,
        public readonly ?string $destination,
        public readonly ?string $gateway,
        public readonly ?string $interface,
        public readonly ?string $source,
        public readonly bool $default,
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
