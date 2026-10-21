<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class SftpTransferResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $sourcePath,
        public readonly string $destinationPath,
        public readonly int $bytesTransferred,
        public readonly float $duration,
        public readonly ?string $error,
    ) {
    }

    public static function success(
        string $source,
        string $destination,
        int $bytes,
        float $duration = 0.0,
    ): self {
        return new self(true, $source, $destination, $bytes, $duration, null);
    }

    public static function failure(
        string $source,
        string $destination,
        string $error,
    ): self {
        return new self(false, $source, $destination, 0, 0.0, $error);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
