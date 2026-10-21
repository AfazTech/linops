<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class NoAutomaticRebootTest extends TestCase
{
    public function testRebootManagerContainsNoRebootInvocation(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/System/RebootManager.php');

        // Inspection code must never execute a reboot command.
        self::assertStringNotContainsString('systemctl reboot', $source);
        self::assertStringNotContainsString('shutdown -r', $source);
        self::assertStringNotContainsString("'reboot'", $source);
    }

    public function testServiceInspectorOnlyRestartsInExplicitMethod(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Service/ServiceInspector.php');

        $restartPos = strpos($source, 'public function restart');
        self::assertNotFalse($restartPos);

        $beforeRestart = substr($source, 0, $restartPos);

        // No restart invocation exists in list/status inspection methods.
        self::assertStringNotContainsString('systemctl restart', $beforeRestart);

        // The explicit restart method does contain the restart command.
        self::assertStringContainsString('systemctl restart', $source);
    }

    public function testRebootManagerDoesNotInvokeReboot(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/System/RebootManager.php');
        self::assertStringNotContainsString('exec(', $source);
    }
}
