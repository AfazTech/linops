<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Integration;

use AfazTech\LinOps\Exception\ConnectionException;
use AfazTech\LinOps\ServerConfig;
use PHPUnit\Framework\TestCase;

final class SecretRedactionTest extends TestCase
{
    public function testDebugInfoDoesNotContainPassword(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'super-secret-password');
        $debug = $config->__debugInfo();
        $encoded = json_encode($debug);

        self::assertStringNotContainsString('super-secret-password', (string) $encoded);
        self::assertSame('***', $debug['password']);
    }

    public function testDebugInfoDoesNotContainPrivateKey(): void
    {
        $config = new ServerConfig(
            host: 'example.com',
            privateKey: '-----BEGIN OPENSSH PRIVATE KEY-----SECRET',
            privateKeyPassphrase: 'passphrase',
        );

        $debug = $config->__debugInfo();
        $encoded = json_encode($debug);

        self::assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', (string) $encoded);
        self::assertStringNotContainsString('SECRET', (string) $encoded);
        self::assertStringNotContainsString('passphrase', (string) $encoded);
        self::assertSame('***', $debug['privateKey']);
        self::assertSame('***', $debug['privateKeyPassphrase']);
    }

    public function testIdentifierDoesNotContainSecrets(): void
    {
        $config = new ServerConfig(
            host: 'example.com',
            password: 'super-secret-password',
            label: 'web-1',
        );
        self::assertStringNotContainsString('super-secret-password', $config->getIdentifier());
        self::assertStringNotContainsString('super-secret-password', $config->getConnectionKey());
    }

    public function testConnectionExceptionMessageDoesNotLeakPassword(): void
    {
        $config = new ServerConfig(host: 'example.com', password: 'super-secret-password');
        $exception = new ConnectionException(sprintf(
            'SSH connection to %s failed',
            $config->getIdentifier(),
        ));

        self::assertStringNotContainsString('super-secret-password', $exception->getMessage());
    }
}
