<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Exception\AuthenticationException;
use AfazTech\LinOps\Exception\ConnectionException;
use AfazTech\LinOps\Exception\HostKeyVerificationException;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\HostKeyPolicy;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Builds authenticated SSH2 and SFTP clients with host-key verification.
 *
 * The host key is verified BEFORE credentials are sent, so a wrong or
 * spoofed server cannot capture the password or private key.
 */
final class SshConnector
{
    public static function connect(ServerConfig $config): SSH2
    {
        $ssh = new SSH2($config->host, $config->port, $config->connectionTimeout);
        $ssh->enableQuietMode();

        self::verifyHostKey($ssh, $config);
        self::authenticate($ssh, $config);

        $ssh->setTimeout($config->commandTimeout);

        return $ssh;
    }

    /**
     * Create a dedicated SFTP client. Passing an existing SSH2 instance to
     * the SFTP constructor does not reuse the connection in phpseclib3 and
     * causes silent failures ("SFTP put failed: no error reported"), so
     * SFTP is always established as its own connection.
     */
    public static function connectSftp(ServerConfig $config): SFTP
    {
        $sftp = new SFTP($config->host, $config->port, $config->connectionTimeout);
        $sftp->enableQuietMode();

        self::verifyHostKey($sftp, $config);
        self::authenticate($sftp, $config);

        $sftp->setTimeout($config->commandTimeout);

        return $sftp;
    }

    private static function authenticate(SSH2 $ssh, ServerConfig $config): void
    {
        try {
            if (!$ssh->login($config->username, self::buildCredential($config))) {
                throw new AuthenticationException(sprintf(
                    'Authentication failed for %s.',
                    $config->getIdentifier(),
                ));
            }
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(sprintf(
                'SSH connection to %s failed: %s',
                $config->getIdentifier(),
                $e->getMessage(),
            ), 0, $e);
        }
    }

    private static function verifyHostKey(SSH2 $ssh, ServerConfig $config): void
    {
        if ($config->hostKeyPolicy === HostKeyPolicy::INSECURE) {
            return;
        }

        try {
            $key = $ssh->getServerPublicHostKey();
        } catch (\Throwable $e) {
            throw new HostKeyVerificationException(
                'Unable to retrieve server host key: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($key === false || $key === '') {
            throw new HostKeyVerificationException('Server did not provide a host key.');
        }

        $parts = preg_split('/\s+/', trim($key));
        if ($parts === false || count($parts) < 2) {
            throw new HostKeyVerificationException('Unparseable server host key.');
        }

        HostKeyVerifier::verify(
            $config->hostKeyPolicy,
            $config->host,
            $config->port,
            $parts[0],
            $parts[1],
            $config->getResolvedKnownHostsFile(),
        );
    }

    private static function buildCredential(ServerConfig $config): mixed
    {
        if ($config->password !== null) {
            return $config->password;
        }

        $key = $config->privateKey;
        if ($key === null) {
            throw new AuthenticationException('No authentication credential configured.');
        }

        if (is_file($key) && is_readable($key)) {
            $key = (string) file_get_contents($key);
        }

        try {
            return $config->privateKeyPassphrase !== null
                ? PublicKeyLoader::load($key, $config->privateKeyPassphrase)
                : PublicKeyLoader::load($key);
        } catch (\Throwable $e) {
            throw new AuthenticationException('Unable to load private key.', 0, $e);
        }
    }
}
