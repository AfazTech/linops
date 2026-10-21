<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Result;

use DateTimeImmutable;

final class CpuLoadInfo
{
    public function __construct(
        public readonly float $load1,
        public readonly float $load5,
        public readonly float $load15,
        public readonly int $logicalCpuCount,
        public readonly float $normalizedLoad,
        public readonly ?float $usagePercent,
        public readonly ?float $userPercent,
        public readonly ?float $systemPercent,
        public readonly ?float $iowaitPercent,
        public readonly ?float $idlePercent,
        public readonly ?float $sampleIntervalSeconds,
        public readonly DateTimeImmutable $collectedAt,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {
    }

    public function isLoadWarning(float $threshold = 1.0): bool
    {
        return $this->normalizedLoad >= $threshold;
    }

    public function isLoadCritical(float $threshold = 2.0): bool
    {
        return $this->normalizedLoad >= $threshold;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'load1' => $this->load1,
            'load5' => $this->load5,
            'load15' => $this->load15,
            'logicalCpuCount' => $this->logicalCpuCount,
            'normalizedLoad' => $this->normalizedLoad,
            'usagePercent' => $this->usagePercent,
            'userPercent' => $this->userPercent,
            'systemPercent' => $this->systemPercent,
            'iowaitPercent' => $this->iowaitPercent,
            'idlePercent' => $this->idlePercent,
            'sampleIntervalSeconds' => $this->sampleIntervalSeconds,
            'collectedAt' => $this->collectedAt->format(DATE_ATOM),
            'raw' => $this->raw,
        ];
    }
}
