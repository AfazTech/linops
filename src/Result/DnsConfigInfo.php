<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class DnsConfigInfo
{
    /**
     * @param list<string> $nameservers
     * @param list<string> $searchDomains
     * @param list<array{ip: string, hostnames: list<string>}> $hosts
     */
    public function __construct(
        public readonly array $nameservers,
        public readonly array $searchDomains,
        public readonly ?string $source,
        public readonly array $hosts = [],
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
