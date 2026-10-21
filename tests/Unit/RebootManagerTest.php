<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\System\RebootManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RebootManagerTest extends TestCase
{
    public function testNeedsRebootReturnsBool(): void
    {
        $method = new \ReflectionMethod(RebootManager::class, 'needsReboot');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('bool', (string) $returnType);
    }

    public function testRebootManagerHasNoExecCall(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(RebootManager::class))->getFileName(),
        );
        self::assertStringNotContainsString('systemctl reboot', $source);
        self::assertStringNotContainsString('shutdown -r', $source);
    }

    public function testRebootManagerDoesNotUseExec(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(RebootManager::class))->getFileName(),
        );
        self::assertStringNotContainsString('exec(', $source);
        self::assertStringNotContainsString('shell_exec(', $source);
    }
}
