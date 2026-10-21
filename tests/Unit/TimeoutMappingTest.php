<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Exception\CommandFailedException;
use AfazTech\LinOps\Exception\CommandTimeoutException;
use AfazTech\LinOps\Result\CommandResult;
use PHPUnit\Framework\TestCase;

final class TimeoutMappingTest extends TestCase
{
    public function testTimeoutResultIsNotSuccess(): void
    {
        $result = new CommandResult(
            command: 'sleep 100',
            success: false,
            exitCode: -1,
            error: 'Command timed out.',
        );

        self::assertFalse($result->success);
        self::assertSame('Command timed out.', $result->error);
        self::assertSame(-1, $result->exitCode);
    }

    public function testTimeoutExceptionCarriesExitCode(): void
    {
        $exception = new CommandTimeoutException('timed out', 124);
        self::assertSame(124, $exception->exitCode);
        self::assertInstanceOf(CommandFailedException::class, $exception);
    }

    public function testCommandFailurePreservesOutput(): void
    {
        $exception = new CommandFailedException('failed', 2, 'stdout-data', 'stderr-data');
        self::assertSame(2, $exception->exitCode);
        self::assertSame('stdout-data', $exception->stdout);
        self::assertSame('stderr-data', $exception->stderr);
    }
}
