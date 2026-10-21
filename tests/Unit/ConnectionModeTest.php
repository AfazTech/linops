<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\ConnectionMode;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\HostKeyPolicy;
use PHPUnit\Framework\TestCase;

final class ConnectionModeTest extends TestCase
{
    public function testDefaultModeIsSsh(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'p');
        self::assertSame(ConnectionMode::SSH, $config->mode);
        self::assertFalse($config->isLocal());
    }

    public function testLocalFactoryProducesValidLocalConfig(): void
    {
        $config = ServerConfig::local();

        self::assertSame(ConnectionMode::LOCAL, $config->mode);
        self::assertTrue($config->isLocal());
        self::assertSame('local', $config->getIdentifier());
        self::assertSame(HostKeyPolicy::INSECURE, $config->hostKeyPolicy);
        self::assertNull($config->password);
        self::assertNull($config->privateKey);
    }

    public function testLocalModeSkipsSshValidation(): void
    {
        // Local mode must not require credentials or a real hostname.
        $config = new ServerConfig(
            host: '',
            port: 0,
            username: '',
            password: null,
            privateKey: null,
            mode: ConnectionMode::LOCAL,
        );

        self::assertTrue($config->isLocal());
    }

    public function testLocalModeStillValidatesTimeouts(): void
    {
        $this->expectException(\AfazTech\LinOps\Exception\InvalidConfigurationException::class);

        new ServerConfig(
            connectionTimeout: 0,
            mode: ConnectionMode::LOCAL,
        );
    }

    public function testLocalConfigRoundTrip(): void
    {
        $original = ServerConfig::local(label: 'host-local');
        $rebuilt = ServerConfig::fromArray($original->toArray());

        self::assertSame(ConnectionMode::LOCAL, $rebuilt->mode);
        self::assertSame('host-local', $rebuilt->getIdentifier());
    }

    public function testConnectionKeyIncludesMode(): void
    {
        $ssh = new ServerConfig(host: 'example.com', username: 'root', password: 'p');
        $local = ServerConfig::local();

        self::assertNotSame($ssh->getConnectionKey(), $local->getConnectionKey());
    }

    public function testLocalConfigDoesNotLeakSecretsInDebug(): void
    {
        $config = ServerConfig::local();
        $debug = $config->__debugInfo();

        self::assertSame('local', $debug['mode']);
        self::assertNull($debug['password']);
        self::assertNull($debug['privateKey']);
    }
}
