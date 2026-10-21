<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Result\OperationResult;
use PHPUnit\Framework\TestCase;

final class OperationResultTest extends TestCase
{
    public function testSuccessResultCarriesData(): void
    {
        $result = OperationResult::success(
            serverIdentifier: 'srv-1',
            operation: 'getHostInfo',
            data: ['hostname' => 'web01'],
        );

        self::assertTrue($result->success);
        self::assertSame('srv-1', $result->serverIdentifier);
        self::assertSame('getHostInfo', $result->operation);
        self::assertSame(['hostname' => 'web01'], $result->data);
        self::assertNull($result->errorType);
    }

    public function testFailureResultCarriesError(): void
    {
        $result = OperationResult::failure(
            serverIdentifier: 'srv-2',
            operation: 'getHostInfo',
            errorType: 'RuntimeException',
            errorMessage: 'connection refused',
        );

        self::assertFalse($result->success);
        self::assertSame('RuntimeException', $result->errorType);
        self::assertSame('connection refused', $result->errorMessage);
        self::assertNull($result->data);
    }

    public function testSuccessAndFailureAreDistinct(): void
    {
        $success = OperationResult::success('a', 'op', 'value');
        $failure = OperationResult::failure('a', 'op', 'E', 'msg');

        self::assertNotSame($success->success, $failure->success);
        self::assertNull($success->errorMessage);
        self::assertNull($failure->data);
    }

    public function testToArrayIncludesAllFields(): void
    {
        $result = OperationResult::success(
            serverIdentifier: 'srv-1',
            operation: 'test',
            data: 'x',
            stdout: 'out',
            stderr: 'err',
            exitCode: 0,
        );

        $array = $result->toArray();

        self::assertArrayHasKey('serverIdentifier', $array);
        self::assertArrayHasKey('operation', $array);
        self::assertArrayHasKey('success', $array);
        self::assertArrayHasKey('data', $array);
        self::assertArrayHasKey('stdout', $array);
        self::assertArrayHasKey('stderr', $array);
        self::assertArrayHasKey('exitCode', $array);
        self::assertArrayHasKey('errorType', $array);
        self::assertArrayHasKey('errorMessage', $array);
        self::assertSame('out', $array['stdout']);
        self::assertSame('err', $array['stderr']);
    }

    public function testWarningsArePreserved(): void
    {
        $result = OperationResult::success(
            serverIdentifier: 'srv-1',
            operation: 'op',
            data: null,
            warnings: ['iowait not reported by this kernel'],
        );

        self::assertSame(['iowait not reported by this kernel'], $result->warnings);
    }
}
