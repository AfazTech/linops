<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

use AfazTech\LinOps\Support\ServerType;

final class ServerTypeInfo
{
    /**
     * @param list<string> $evidence
     * @param array<string, string> $raw
     */
    public function __construct(
        public readonly ServerType $type,
        public readonly float $confidence,
        public readonly ?string $hypervisor,
        public readonly array $evidence,
        public readonly array $raw = [],
    ) {
    }

    public function isVps(): bool
    {
        return $this->type === ServerType::VPS;
    }

    public function isDedicated(): bool
    {
        return $this->type === ServerType::DEDICATED;
    }

    public function isUnknown(): bool
    {
        return $this->type === ServerType::UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'confidence' => $this->confidence,
            'hypervisor' => $this->hypervisor,
            'evidence' => $this->evidence,
            'raw' => $this->raw,
        ];
    }
}
