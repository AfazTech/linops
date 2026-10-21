<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

use DateTimeImmutable;

final class LoginRecord
{
    public function __construct(
        public readonly string $user,
        public readonly ?string $tty,
        public readonly ?string $host,
        public readonly ?DateTimeImmutable $timestamp,
        public readonly bool $success,
        public readonly ?string $details,
        public readonly string $raw,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'tty' => $this->tty,
            'host' => $this->host,
            'timestamp' => $this->timestamp?->format(DATE_ATOM),
            'success' => $this->success,
            'details' => $this->details,
            'raw' => $this->raw,
        ];
    }
}
