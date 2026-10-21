<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class CommandResult
{
    public function __construct(
        public readonly string $command,
        public readonly bool $success,
        public readonly ?string $stdout = null,
        public readonly ?string $stderr = null,
        public readonly ?int $exitCode = null,
        public readonly float $duration = 0.0,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            command: (string) $data['command'],
            success: (bool) $data['success'],
            stdout: isset($data['stdout']) ? (string) $data['stdout'] : null,
            stderr: isset($data['stderr']) ? (string) $data['stderr'] : null,
            exitCode: isset($data['exitCode']) ? (int) $data['exitCode'] : null,
            duration: (float) ($data['duration'] ?? 0.0),
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}
