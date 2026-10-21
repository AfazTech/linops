<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Exception\InvalidConfigurationException;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\HostKeyPolicy;
use PHPUnit\Framework\TestCase;

final class ServerConfigTest extends TestCase
{
    public function testAcceptsPasswordAuthentication(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'secret');
        self::assertSame('example.com', $config->host);
        self::assertSame('secret', $config->password);
        self::assertNull($config->privateKey);
    }

    public function testAcceptsPrivateKeyAuthentication(): void
    {
        $config = new ServerConfig(host: 'example.com', privateKey: 'key-material');
        self::assertSame('key-material', $config->privateKey);
        self::assertNull($config->password);
    }

    public function testAcceptsPrivateKeyWithPassphrase(): void
    {
        $config = new ServerConfig(
            host: 'example.com',
            privateKey: 'encrypted-key',
            privateKeyPassphrase: 'passphrase-value',
        );
        self::assertSame('passphrase-value', $config->privateKeyPassphrase);
    }

    public function testRejectsBothPasswordAndKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Provide either password or privateKey');
        new ServerConfig(host: 'example.com', password: 'p', privateKey: 'k');
    }

    public function testRejectsNoCredentials(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Either password or privateKey must be provided');
        new ServerConfig(host: 'example.com');
    }

    public function testRejectsEmptyHost(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Host must not be empty');
        new ServerConfig(host: '  ', password: 'p');
    }

    public function testRejectsPortBelowOne(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Port must be between');
        new ServerConfig(host: 'example.com', port: 0, password: 'p');
    }

    public function testRejectsPortAbove65535(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Port must be between');
        new ServerConfig(host: 'example.com', port: 65536, password: 'p');
    }

    public function testRejectsEmptyUsername(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Username must not be empty');
        new ServerConfig(host: 'example.com', username: '', password: 'p');
    }

    public function testRejectsZeroConnectionTimeout(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Connection timeout');
        new ServerConfig(host: 'example.com', password: 'p', connectionTimeout: 0);
    }

    public function testRejectsNegativeCommandTimeout(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Command timeout');
        new ServerConfig(host: 'example.com', password: 'p', commandTimeout: -1);
    }

    public function testPassphraseWithoutKeyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Private key passphrase requires a private key');
        new ServerConfig(host: 'example.com', password: 'p', privateKeyPassphrase: 'x');
    }

    public function testDefaultHostKeyPolicyIsStrict(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'p');
        self::assertSame(HostKeyPolicy::STRICT, $config->hostKeyPolicy);
    }

    public function testIdentifierUsesLabel(): void
    {
        $config = new ServerConfig(host: '10.0.0.1', password: 'p', label: 'web-1');
        self::assertSame('web-1', $config->getIdentifier());
    }

    public function testIdentifierFallsBackToUserHostPort(): void
    {
        $config = new ServerConfig(host: '10.0.0.1', port: 2222, username: 'ops', password: 'p');
        self::assertSame('ops@10.0.0.1:2222', $config->getIdentifier());
    }

    public function testConnectionKeyDoesNotContainSecrets(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'top-secret');
        self::assertStringNotContainsString('top-secret', $config->getConnectionKey());
        self::assertSame(64, strlen($config->getConnectionKey()));
    }

    public function testDebugInfoHidesPassword(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'top-secret-value');
        $debug = $config->__debugInfo();
        self::assertSame('***', $debug['password']);
        self::assertStringNotContainsString('top-secret-value', json_encode($debug) ?: '');
    }

    public function testDebugInfoHidesPrivateKey(): void
    {
        $config = new ServerConfig(host: 'example.com', privateKey: 'BEGIN_KEY_SECRET_MATERIAL');
        $debug = $config->__debugInfo();
        self::assertSame('***', $debug['privateKey']);
        self::assertStringNotContainsString('BEGIN_KEY_SECRET_MATERIAL', json_encode($debug) ?: '');
    }

    public function testDebugInfoHidesPassphraseValue(): void
    {
        $config = new ServerConfig(
            host: 'example.com',
            privateKey: 'key',
            privateKeyPassphrase: 'hunter2-very-secret-value',
        );
        $debug = $config->__debugInfo();
        self::assertSame('***', $debug['privateKeyPassphrase']);
        self::assertStringNotContainsString('hunter2-very-secret-value', json_encode($debug) ?: '');
    }

    public function testArrayRoundTrip(): void
    {
        $original = new ServerConfig(
            host: 'example.com',
            port: 2222,
            username: 'ops',
            password: 'p',
            connectionTimeout: 5.0,
            commandTimeout: 60.0,
            hostKeyPolicy: HostKeyPolicy::TOFU,
            knownHostsFile: '/tmp/known_hosts',
            label: 'srv-1',
        );

        $rebuilt = ServerConfig::fromArray($original->toArray());

        self::assertSame($original->host, $rebuilt->host);
        self::assertSame($original->port, $rebuilt->port);
        self::assertSame($original->username, $rebuilt->username);
        self::assertSame($original->password, $rebuilt->password);
        self::assertSame($original->connectionTimeout, $rebuilt->connectionTimeout);
        self::assertSame($original->commandTimeout, $rebuilt->commandTimeout);
        self::assertSame($original->hostKeyPolicy, $rebuilt->hostKeyPolicy);
        self::assertSame($original->knownHostsFile, $rebuilt->knownHostsFile);
        self::assertSame($original->label, $rebuilt->label);
    }
}
