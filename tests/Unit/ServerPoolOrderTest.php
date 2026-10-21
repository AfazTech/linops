<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\ServerPool;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

final class ServerPoolOrderTest extends TestCase
{
    public function testCollectPreservesInputOrderByDefault(): void
    {
        $pool = new ServerPool(concurrency: 4);
        $servers = [];
        for ($i = 0; $i < 4; $i++) {
            $servers[] = new ServerConfig(host: 'h' . $i, password: 'p', label: 'srv-' . $i);
        }

        // Deliberately inverted delays: first server is slowest.
        $delays = ['srv-0' => 0.08, 'srv-1' => 0.01, 'srv-2' => 0.05, 'srv-3' => 0.02];

        $results = $pool->collect(
            $servers,
            static function (ServerConfig $server) use ($delays): string {
                delay($delays[$server->label ?? ''] ?? 0.0);
                return (string) $server->label;
            },
        );

        self::assertCount(4, $results);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(
                'srv-' . $i,
                $results[$i]->serverIdentifier,
                sprintf('Result at index %d must be srv-%d, regardless of completion order.', $i, $i),
            );
        }
    }

    public function testCollectWithoutPreservingOrderReturnsAllResults(): void
    {
        $pool = new ServerPool(concurrency: 4);
        $servers = [
            new ServerConfig(host: 'a', password: 'p', label: 'srv-a'),
            new ServerConfig(host: 'b', password: 'p', label: 'srv-b'),
        ];

        $results = $pool->collect(
            $servers,
            static fn (ServerConfig $s) => $s->label,
            null,
            false,
        );

        self::assertCount(2, $results);
        $ids = array_map(static fn ($r) => $r->serverIdentifier, $results);
        sort($ids);
        self::assertSame(['srv-a', 'srv-b'], $ids);
    }

    public function testCollectWithEmptyInputReturnsEmptyArray(): void
    {
        $pool = new ServerPool(concurrency: 2);
        self::assertSame([], $pool->collect([], static fn () => null));
    }

    public function testCollectWithSingleServerReturnsOneResult(): void
    {
        $pool = new ServerPool(concurrency: 2);
        $servers = [new ServerConfig(host: 'only', password: 'p', label: 'only')];

        $results = $pool->collect($servers, static fn () => 'value');

        self::assertCount(1, $results);
        self::assertSame('only', $results[0]->serverIdentifier);
        self::assertTrue($results[0]->success);
    }
}
