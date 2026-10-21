<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Exception\HostKeyVerificationException;
use AfazTech\LinOps\Support\HostKeyPolicy;

final class HostKeyVerifier
{
    /**
     * @return bool True if the key was accepted and stored (TOFU) or already known.
     *
     * @throws HostKeyVerificationException
     */
    public static function verify(
        HostKeyPolicy $policy,
        string $host,
        int $port,
        string $keyType,
        string $keyBase64,
        string $knownHostsFile,
    ): bool {
        if ($policy === HostKeyPolicy::INSECURE) {
            return true;
        }

        $hostPatterns = self::hostPatterns($host, $port);
        $entries = self::readKnownHosts($knownHostsFile);

        foreach ($entries as $entry) {
            if (!self::hostMatches($entry['hosts'], $hostPatterns)) {
                continue;
            }

            if ($entry['keyType'] !== $keyType) {
                throw new HostKeyVerificationException(sprintf(
                    'Host key type mismatch for %s:%d: expected "%s", got "%s".',
                    $host,
                    $port,
                    $entry['keyType'],
                    $keyType,
                ));
            }

            if (!hash_equals($entry['keyBase64'], $keyBase64)) {
                throw new HostKeyVerificationException(sprintf(
                    'Host key mismatch for %s:%d. Possible man-in-the-middle attack.',
                    $host,
                    $port,
                ));
            }

            return true;
        }

        if ($policy === HostKeyPolicy::TOFU) {
            self::appendKnownHost($knownHostsFile, $host, $port, $keyType, $keyBase64);
            return true;
        }

        throw new HostKeyVerificationException(sprintf(
            'Unknown host key for %s:%d. Add the key to %s or use HostKeyPolicy::TOFU.',
            $host,
            $port,
            $knownHostsFile,
        ));
    }

    /**
     * @return list<array{hosts: list<string>, keyType: string, keyBase64: string}>
     */
    private static function readKnownHosts(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $entries = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 3) {
                continue;
            }

            $hosts = explode(',', $parts[0]);
            $entries[] = [
                'hosts' => $hosts,
                'keyType' => $parts[1],
                'keyBase64' => $parts[2],
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function hostPatterns(string $host, int $port): array
    {
        $patterns = [$host];
        if ($port !== 22) {
            $patterns[] = sprintf('[%s]:%d', $host, $port);
        }

        return $patterns;
    }

    /**
     * @param list<string> $knownHosts
     * @param list<string> $patterns
     */
    private static function hostMatches(array $knownHosts, array $patterns): bool
    {
        foreach ($knownHosts as $known) {
            foreach ($patterns as $pattern) {
                if ($known === $pattern) {
                    return true;
                }

                // Support simple wildcard patterns such as *.example.com
                if (str_contains($known, '*')) {
                    $regex = '/^' . str_replace('\\*', '.*', preg_quote($known, '/')) . '$/i';
                    if (preg_match($regex, $pattern) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function appendKnownHost(
        string $file,
        string $host,
        int $port,
        string $keyType,
        string $keyBase64,
    ): void {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $hostPart = $port === 22 ? $host : sprintf('[%s]:%d', $host, $port);
        $line = sprintf("%s %s %s\n", $hostPart, $keyType, $keyBase64);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
