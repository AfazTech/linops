<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class ListeningPort
{
    public function __construct(
        public readonly string $protocol,
        public readonly string $address,
        public readonly int $port,
        public readonly ?string $processName,
        public readonly ?int $pid,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
