<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Result\OperationResult;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\ServerPool;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

final class StreamingTest extends TestCase
{
    public function testStreamYieldsFirstResultBeforeSlowOne(): void
    {
        $pool = new ServerPool(concurrency: 2);
        $servers = [
            new ServerConfig(host: 'fast', password: 'p', label: 'fast'),
            new ServerConfig(host: 'slow', password: 'p', label: 'slow'),
        ];

        $order = [];
        foreach (
            $pool->stream($servers, function (ServerConfig $server): string {
                delay($server->label === 'fast' ? 0.02 : 0.3);
                return (string) $server->label;
            }) as $result
        ) {
            self::assertInstanceOf(OperationResult::class, $result);
            self::assertTrue($result->success);
            $order[] = $result->serverIdentifier;
        }

        self::assertCount(2, $order);
        self::assertSame('fast', $order[0], 'Fast server must be yielded first.');
        self::assertSame('slow', $order[1]);
    }

    public function testStreamYieldsAllResults(): void
    {
        $pool = new ServerPool(concurrency: 3);
        $servers = [];
        for ($i = 0; $i < 3; $i++) {
            $servers[] = new ServerConfig(host: 'h' . $i, password: 'p', label: 'srv-' . $i);
        }

        $collected = [];
        foreach ($pool->stream($servers, static fn (ServerConfig $s) => $s->label) as $result) {
            $collected[] = $result;
        }

        self::assertCount(3, $collected);
        $identifiers = array_map(
            static fn (OperationResult $r): ?string => $r->serverIdentifier,
            $collected,
        );
        sort($identifiers);
        self::assertSame(['srv-0', 'srv-1', 'srv-2'], $identifiers);
    }

    public function testStreamReportsFailuresIndependently(): void
    {
        $pool = new ServerPool(concurrency: 2);
        $servers = [
            new ServerConfig(host: 'good', password: 'p', label: 'good'),
            new ServerConfig(host: 'bad', password: 'p', label: 'bad'),
        ];

        $results = [];
        foreach (
            $pool->stream($servers, static function (ServerConfig $s): string {
                if ($s->label === 'bad') {
                    throw new \RuntimeException('boom');
                }
                return 'ok';
            }) as $result
        ) {
            $results[] = $result;
        }

        self::assertCount(2, $results);
        $successCount = count(array_filter($results, static fn (OperationResult $r): bool => $r->success));
        $failureCount = count(array_filter($results, static fn (OperationResult $r): bool => !$r->success));
        self::assertSame(1, $successCount);
        self::assertSame(1, $failureCount);
    }
}
