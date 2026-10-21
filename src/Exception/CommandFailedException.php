<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Exception;

class CommandFailedException extends LinOpsException
{
    public function __construct(
        string $message,
        public readonly ?int $exitCode = null,
        public readonly ?string $stdout = null,
        public readonly ?string $stderr = null,
    ) {
        parent::__construct($message, $exitCode ?? 0);
    }
}
