<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\Exception\HostKeyVerificationException;
use AfazTech\LinOps\Support\HostKeyPolicy;
use AfazTech\LinOps\Transport\HostKeyVerifier;
use PHPUnit\Framework\TestCase;

final class HostKeyVerifierTest extends TestCase
{
    private string $knownHostsFile;

    protected function setUp(): void
    {
        $this->knownHostsFile = tempnam(sys_get_temp_dir(), 'known_hosts_test_') ?: '';
        self::assertNotSame('', $this->knownHostsFile);
    }

    protected function tearDown(): void
    {
        if ($this->knownHostsFile !== '' && is_file($this->knownHostsFile)) {
            unlink($this->knownHostsFile);
        }
    }

    public function testStrictRejectsUnknownHost(): void
    {
        file_put_contents($this->knownHostsFile, '');

        $this->expectException(HostKeyVerificationException::class);
        $this->expectExceptionMessage('Unknown host key');

        HostKeyVerifier::verify(
            HostKeyPolicy::STRICT,
            'example.com',
            22,
            'ssh-ed25519',
            'AAAATESTKEY',
            $this->knownHostsFile,
        );
    }

    public function testStrictAcceptsKnownHostWithMatchingKey(): void
    {
        file_put_contents($this->knownHostsFile, "example.com ssh-ed25519 AAAATESTKEY\n");

        $result = HostKeyVerifier::verify(
            HostKeyPolicy::STRICT,
            'example.com',
            22,
            'ssh-ed25519',
            'AAAATESTKEY',
            $this->knownHostsFile,
        );

        self::assertTrue($result);
    }

    public function testStrictRejectsKeyMismatch(): void
    {
        file_put_contents($this->knownHostsFile, "example.com ssh-ed25519 AAAADIFFERENT\n");

        $this->expectException(HostKeyVerificationException::class);
        $this->expectExceptionMessage('Host key mismatch');

        HostKeyVerifier::verify(
            HostKeyPolicy::STRICT,
            'example.com',
            22,
            'ssh-ed25519',
            'AAAATESTKEY',
            $this->knownHostsFile,
        );
    }

    public function testStrictRejectsKeyTypeMismatch(): void
    {
        file_put_contents($this->knownHostsFile, "example.com ssh-ed25519 AAAATESTKEY\n");

        $this->expectException(HostKeyVerificationException::class);
        $this->expectExceptionMessage('Host key type mismatch');

        HostKeyVerifier::verify(
            HostKeyPolicy::STRICT,
            'example.com',
            22,
            'ssh-rsa',
            'AAAATESTKEY',
            $this->knownHostsFile,
        );
    }

    public function testTofuStoresUnknownHostKey(): void
    {
        file_put_contents($this->knownHostsFile, '');

        $result = HostKeyVerifier::verify(
            HostKeyPolicy::TOFU,
            'new-host.example.com',
            22,
            'ssh-ed25519',
            'AAAANEWKEY',
            $this->knownHostsFile,
        );

        self::assertTrue($result);
        $contents = (string) file_get_contents($this->knownHostsFile);
        self::assertStringContainsString('new-host.example.com ssh-ed25519 AAAANEWKEY', $contents);
    }

    public function testTofuStillRejectsMismatch(): void
    {
        file_put_contents($this->knownHostsFile, "example.com ssh-ed25519 AAAAKNOWN\n");

        $this->expectException(HostKeyVerificationException::class);

        HostKeyVerifier::verify(
            HostKeyPolicy::TOFU,
            'example.com',
            22,
            'ssh-ed25519',
            'AAAADIFFERENT',
            $this->knownHostsFile,
        );
    }

    public function testInsecureAcceptsAnyKey(): void
    {
        $result = HostKeyVerifier::verify(
            HostKeyPolicy::INSECURE,
            'example.com',
            22,
            'ssh-ed25519',
            'AAAATESTKEY',
            $this->knownHostsFile,
        );

        self::assertTrue($result);
    }

    public function testNonStandardPortUsesBracketedPattern(): void
    {
        file_put_contents(
            $this->knownHostsFile,
            "[example.com]:2222 ssh-ed25519 AAAAPORTKEY\n",
        );

        $result = HostKeyVerifier::verify(
            HostKeyPolicy::STRICT,
            'example.com',
            2222,
            'ssh-ed25519',
            'AAAAPORTKEY',
            $this->knownHostsFile,
        );

        self::assertTrue($result);
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        file_put_contents(
            $this->knownHostsFile,
            "# This is a comment\n\n   \nexample.com ssh-ed25519 AAAATESTKEY\n",
        );

        $result = HostKeyVerifier::verify(
            HostKeyPolicy::STRICT,
            'example.com',
            22,
            'ssh-ed25519',
            'AAAATESTKEY',
            $this->knownHostsFile,
        );

        self::assertTrue($result);
    }
}
