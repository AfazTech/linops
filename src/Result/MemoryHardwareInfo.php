<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

final class MemoryHardwareInfo
{
    /**
     * @param list<MemoryModuleInfo> $modules
     */
    public function __construct(
        public readonly ?int $totalInstalledBytes,
        public readonly ?int $moduleCount,
        public readonly array $modules,
        public readonly ?string $source,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'totalInstalledBytes' => $this->totalInstalledBytes,
            'moduleCount' => $this->moduleCount,
            'modules' => array_map(
                static fn (MemoryModuleInfo $m): array => $m->toArray(),
                $this->modules,
            ),
            'source' => $this->source,
        ];
    }
}
