<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Network;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\InterfaceTrafficInfo;
use AfazTech\LinOps\ServerConfig;

final class NetworkMonitor
{
    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    /**
     * @param list<string>|null $interfaces
     * @return list<InterfaceTrafficInfo>
     */
    public function getTraffic(
        ServerConfig $config,
        ?array $interfaces = null,
        float $sampleInterval = 1.0,
    ): array {
        $first = $this->readCounters($config);
        usleep((int) ($sampleInterval * 1_000_000));
        $second = $this->readCounters($config);

        $result = [];
        foreach ($second as $name => $counters) {
            if ($interfaces !== null && !in_array($name, $interfaces, true)) {
                continue;
            }

            $previous = $first[$name] ?? null;
            $counterReset = $previous === null
                || $counters['rxBytes'] < $previous['rxBytes']
                || $counters['txBytes'] < $previous['txBytes'];

            $rxRate = null;
            $txRate = null;
            $rxBits = null;
            $txBits = null;

            if (!$counterReset && $sampleInterval > 0) {
                $rxBytesDelta = $counters['rxBytes'] - $previous['rxBytes'];
                $txBytesDelta = $counters['txBytes'] - $previous['txBytes'];
                $rxRate = $rxBytesDelta / $sampleInterval;
                $txRate = $txBytesDelta / $sampleInterval;
                $rxBits = $rxRate * 8;
                $txBits = $txRate * 8;
            }

            $result[] = new InterfaceTrafficInfo(
                interface: $name,
                rxBytes: $counters['rxBytes'],
                txBytes: $counters['txBytes'],
                rxPackets: $counters['rxPackets'],
                txPackets: $counters['txPackets'],
                rxErrors: $counters['rxErrors'],
                txErrors: $counters['txErrors'],
                rxDrops: $counters['rxDrops'],
                txDrops: $counters['txDrops'],
                rxBytesPerSecond: $rxRate,
                txBytesPerSecond: $txRate,
                rxBitsPerSecond: $rxBits,
                txBitsPerSecond: $txBits,
                sampleIntervalSeconds: $sampleInterval,
                counterReset: $counterReset,
            );
        }

        return $result;
    }

    /**
     * @return array<string, array{rxBytes: int, txBytes: int, rxPackets: int, txPackets: int, rxErrors: int, txErrors: int, rxDrops: int, txDrops: int}>
     */
    private function readCounters(ServerConfig $config): array
    {
        $result = $this->executor->executeSingle($config, 'cat /proc/net/dev');
        $counters = [];

        foreach (explode("\n", (string) $result->stdout) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $data] = explode(':', $line, 2);
            $name = trim($name);
            if ($name === '' || $name === 'Inter-|' || $name === 'face') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($data));
            if ($parts === false || count($parts) < 16) {
                continue;
            }

            $counters[$name] = [
                'rxBytes' => (int) $parts[0],
                'rxPackets' => (int) $parts[1],
                'rxErrors' => (int) $parts[2],
                'rxDrops' => (int) $parts[3],
                'txBytes' => (int) $parts[8],
                'txPackets' => (int) $parts[9],
                'txErrors' => (int) $parts[10],
                'txDrops' => (int) $parts[11],
            ];
        }

        return $counters;
    }
}
