<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class NetworkAddressInfo
{
    public function __construct(
        public readonly string $interface,
        public readonly string $address,
        public readonly string $family,
        public readonly ?string $macAddress,
        public readonly bool $loopback,
        public readonly bool $linkLocal,
        public readonly bool $private,
        public readonly bool $public,
        public readonly ?string $scope,
        public readonly bool $secondary,
        public readonly ?int $cidr = null,
        public readonly ?string $netmask = null,
        public readonly ?string $subnet = null,
        public readonly ?string $broadcast = null,
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
