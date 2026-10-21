<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Result\OperationResult;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\ServerPool;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

final class ConcurrencyTest extends TestCase
{
    public function testConcurrencyLimitIsRespected(): void
    {
        $pool = new ServerPool(concurrency: 2);
        $servers = [];
        for ($i = 0; $i < 6; $i++) {
            $servers[] = new ServerConfig(
                host: 'host-' . $i,
                password: 'p',
                label: 'srv-' . $i,
            );
        }

        $active = 0;
        $maxActive = 0;

        $results = $pool->collect(
            $servers,
            function (ServerConfig $server) use (&$active, &$maxActive): string {
                $active++;
                $maxActive = max($maxActive, $active);
                delay(0.02);
                $active--;
                return $server->getIdentifier();
            },
        );

        self::assertCount(6, $results);
        self::assertLessThanOrEqual(2, $maxActive);
    }

    public function testConcurrencyOfOneRunsSequentially(): void
    {
        $pool = new ServerPool(concurrency: 1);
        $servers = [
            new ServerConfig(host: 'a', password: 'p', label: 'srv-a'),
            new ServerConfig(host: 'b', password: 'p', label: 'srv-b'),
        ];

        $active = 0;
        $maxActive = 0;

        $pool->collect($servers, function () use (&$active, &$maxActive): void {
            $active++;
            $maxActive = max($maxActive, $active);
            delay(0.01);
            $active--;
        });

        self::assertSame(1, $maxActive);
    }

    public function testFailedServerDoesNotCancelOthers(): void
    {
        $pool = new ServerPool(concurrency: 4);
        $servers = [
            new ServerConfig(host: 'good-1', password: 'p', label: 'good-1'),
            new ServerConfig(host: 'bad', password: 'p', label: 'bad'),
            new ServerConfig(host: 'good-2', password: 'p', label: 'good-2'),
        ];

        $results = $pool->collect($servers, function (ServerConfig $server): string {
            if ($server->label === 'bad') {
                throw new \RuntimeException('simulated failure');
            }
            return 'ok';
        });

        self::assertCount(3, $results);

        $successful = array_filter($results, static fn (OperationResult $r): bool => $r->success);
        $failed = array_filter($results, static fn (OperationResult $r): bool => !$r->success);

        self::assertCount(2, $successful);
        self::assertCount(1, $failed);

        $failedResult = array_values($failed)[0];
        self::assertSame('bad', $failedResult->serverIdentifier);
        self::assertSame('RuntimeException', $failedResult->errorType);
        self::assertSame('simulated failure', $failedResult->errorMessage);
    }

    public function testEmptyServerListReturnsEmptyResults(): void
    {
        $pool = new ServerPool(concurrency: 5);
        $results = $pool->collect([], static fn () => null);
        self::assertSame([], $results);
    }

    public function testAllFailuresAreReportedIndependently(): void
    {
        $pool = new ServerPool(concurrency: 3);
        $servers = [
            new ServerConfig(host: 'a', password: 'p', label: 'a'),
            new ServerConfig(host: 'b', password: 'p', label: 'b'),
        ];

        $results = $pool->collect($servers, static function (): void {
            throw new \LogicException('always fails');
        });

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertFalse($result->success);
            self::assertSame('LogicException', $result->errorType);
        }
    }
}
