<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class ProcessInfo
{
    public function __construct(
        public readonly int $pid,
        public readonly int $ppid,
        public readonly string $user,
        public readonly float $cpuPercent,
        public readonly float $memPercent,
        public readonly int $rssKb,
        public readonly string $state,
        public readonly string $command,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
