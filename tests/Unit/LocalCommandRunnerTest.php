<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Command\LocalCommandRunner;
use PHPUnit\Framework\TestCase;

final class LocalCommandRunnerTest extends TestCase
{
    public function testEchoReturnsStdoutAndZeroExit(): void
    {
        $runner = new LocalCommandRunner();
        $results = $runner->execute(['echo hello'], 5.0);

        self::assertCount(1, $results);
        self::assertTrue($results[0]->success);
        self::assertSame("hello\n", $results[0]->stdout);
        self::assertSame('', $results[0]->stderr);
        self::assertSame(0, $results[0]->exitCode);
    }

    public function testNonZeroExitIsCaptured(): void
    {
        $runner = new LocalCommandRunner();
        $results = $runner->execute(['exit 3'], 5.0);

        self::assertCount(1, $results);
        self::assertFalse($results[0]->success);
        self::assertSame(3, $results[0]->exitCode);
    }

    public function testStderrIsSeparated(): void
    {
        $runner = new LocalCommandRunner();
        $results = $runner->execute(['echo err >&2; echo out'], 5.0);

        self::assertCount(1, $results);
        self::assertSame("out\n", $results[0]->stdout);
        self::assertSame("err\n", $results[0]->stderr);
    }

    public function testMultipleCommandsRunSequentially(): void
    {
        $runner = new LocalCommandRunner();
        $results = $runner->execute(['echo a', 'echo b', 'echo c'], 5.0);

        self::assertCount(3, $results);
        self::assertSame("a\n", $results[0]->stdout);
        self::assertSame("b\n", $results[1]->stdout);
        self::assertSame("c\n", $results[2]->stdout);
        foreach ($results as $r) {
            self::assertTrue($r->success);
        }
    }

    public function testTimeoutProducesFailureWithMinusOneExit(): void
    {
        $runner = new LocalCommandRunner();
        $results = $runner->execute(['sleep 10'], 0.3);

        self::assertCount(1, $results);
        self::assertFalse($results[0]->success);
        self::assertSame(-1, $results[0]->exitCode);
        self::assertNotNull($results[0]->error);
        self::assertLessThan(2.0, $results[0]->duration);
    }

    public function testOutputIsCappedAtMaxBytes(): void
    {
        $runner = new LocalCommandRunner(maxOutputBytes: 100);
        $results = $runner->execute(
            ['printf "x%.0s" $(seq 1 500)'],
            5.0,
        );

        self::assertCount(1, $results);
        self::assertLessThanOrEqual(100, strlen((string) $results[0]->stdout));
    }

    public function testDurationIsReported(): void
    {
        $runner = new LocalCommandRunner();
        $results = $runner->execute(['true'], 5.0);

        self::assertGreaterThan(0.0, $results[0]->duration);
    }
}
