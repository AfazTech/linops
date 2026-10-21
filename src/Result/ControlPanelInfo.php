<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class ControlPanelInfo
{
    /**
     * @param list<string> $services
     * @param list<string> $evidence
     */
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $version,
        public readonly ?string $installationPath,
        public readonly array $services,
        public readonly float $confidence,
        public readonly array $evidence,
        public readonly string $status,
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
