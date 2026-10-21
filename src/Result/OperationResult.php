<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Generic result envelope for a single server operation.
 *
 * @template T
 */
final class OperationResult
{
    /**
     * @param T|null $data
     * @param array<string, mixed> $warnings
     */
    public function __construct(
        public readonly string $serverIdentifier,
        public readonly string $operation,
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly ?string $stdout = null,
        public readonly ?string $stderr = null,
        public readonly ?int $exitCode = null,
        public readonly float $duration = 0.0,
        public readonly ?DateTimeImmutable $startedAt = null,
        public readonly ?DateTimeImmutable $completedAt = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
        public readonly array $warnings = [],
    ) {
    }

    public static function success(
        string $serverIdentifier,
        string $operation,
        mixed $data = null,
        ?string $stdout = null,
        ?string $stderr = null,
        ?int $exitCode = null,
        float $duration = 0.0,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $completedAt = null,
        array $warnings = [],
    ): self {
        return new self(
            serverIdentifier: $serverIdentifier,
            operation: $operation,
            success: true,
            data: $data,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            duration: $duration,
            startedAt: $startedAt,
            completedAt: $completedAt,
            warnings: $warnings,
        );
    }

    public static function failure(
        string $serverIdentifier,
        string $operation,
        string $errorType,
        string $errorMessage,
        ?string $stdout = null,
        ?string $stderr = null,
        ?int $exitCode = null,
        float $duration = 0.0,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $completedAt = null,
        array $warnings = [],
    ): self {
        return new self(
            serverIdentifier: $serverIdentifier,
            operation: $operation,
            success: false,
            data: null,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            duration: $duration,
            startedAt: $startedAt,
            completedAt: $completedAt,
            errorType: $errorType,
            errorMessage: $errorMessage,
            warnings: $warnings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'serverIdentifier' => $this->serverIdentifier,
            'operation' => $this->operation,
            'success' => $this->success,
            'data' => $this->data,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'exitCode' => $this->exitCode,
            'duration' => $this->duration,
            'startedAt' => $this->startedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'completedAt' => $this->completedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'errorType' => $this->errorType,
            'errorMessage' => $this->errorMessage,
            'warnings' => $this->warnings,
        ];
    }
}
