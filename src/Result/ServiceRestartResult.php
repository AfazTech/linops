<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class ServiceRestartResult
{
    public function __construct(
        public readonly string $service,
        public readonly bool $success,
        public readonly ?int $exitCode,
        public readonly ?string $stdout,
        public readonly ?string $stderr,
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
