<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Exception\ConnectionException;
use AfazTech\LinOps\Exception\LinOpsException;
use AfazTech\LinOps\Result\CommandResult;
use AfazTech\LinOps\Result\SftpTransferResult;
use AfazTech\LinOps\ServerConfig;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Blocking phpseclib transport with SSH exec and SFTP file operations.
 *
 * The exec connection and the SFTP connection are kept separate. Wrapping an
 * existing SSH2 object in SFTP does not reuse the connection in phpseclib3
 * and produces silent "SFTP put failed" errors, so a dedicated SFTP client
 * is opened lazily on first use.
 *
 * IMPORTANT: This class performs blocking network I/O. It MUST only be used
 * inside a worker process, never directly on the Amp event loop.
 */
final class PhpseclibTransport implements SshTransportInterface
{
    private ?SSH2 $ssh = null;
    private ?SFTP $sftp = null;
    private ?ServerConfig $config = null;

    public function connect(ServerConfig $config): void
    {
        if ($this->ssh !== null) {
            $this->disconnect();
        }

        $this->config = $config;
        $this->ssh = SshConnector::connect($config);
    }

    public function disconnect(): void
    {
        if ($this->sftp !== null) {
            try {
                $this->sftp->disconnect();
            } catch (\Throwable) {
                // Best-effort.
            }
            $this->sftp = null;
        }

        if ($this->ssh !== null) {
            try {
                $this->ssh->disconnect();
            } catch (\Throwable) {
                // Best-effort.
            }
        }

        $this->ssh = null;
        $this->config = null;
    }

    public function isConnected(): bool
    {
        if ($this->ssh === null) {
            return false;
        }

        try {
            return $this->ssh->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $commands
     * @return list<CommandResult>
     */
    public function executeCommands(array $commands, ?float $timeoutOverride = null): array
    {
        if ($this->ssh === null || $this->config === null) {
            throw new ConnectionException('Not connected.');
        }

        $results = [];
        $previousTimeout = $this->config->commandTimeout;
        if ($timeoutOverride !== null) {
            $this->ssh->setTimeout($timeoutOverride);
        }

        try {
            foreach ($commands as $command) {
                $start = microtime(true);
                try {
                    $stdout = $this->ssh->exec($command);
                    $exitCode = $this->ssh->getExitStatus();
                    $results[] = new CommandResult(
                        command: $command,
                        success: $exitCode === 0,
                        stdout: is_string($stdout) ? $stdout : null,
                        stderr: $this->ssh->getStdError(),
                        exitCode: $exitCode,
                        duration: microtime(true) - $start,
                    );
                } catch (\Throwable $e) {
                    $results[] = new CommandResult(
                        command: $command,
                        success: false,
                        error: $e->getMessage(),
                        duration: microtime(true) - $start,
                    );
                }
            }
        } finally {
            if ($timeoutOverride !== null) {
                $this->ssh->setTimeout($previousTimeout);
            }
        }

        return $results;
    }

    /**
     * Lazily create and reuse a dedicated SFTP connection.
     */
    private function getSftp(): SFTP
    {
        if ($this->config === null) {
            throw new ConnectionException('Not connected.');
        }

        if ($this->sftp === null || !$this->sftp->isConnected()) {
            $this->sftp = SshConnector::connectSftp($this->config);
        }

        return $this->sftp;
    }

    /**
     * Upload a local file to the remote host atomically (temp + rename).
     */
    public function upload(string $localPath, string $remotePath): SftpTransferResult
    {
        if ($this->config === null) {
            return SftpTransferResult::failure($localPath, $remotePath, 'Not connected.');
        }

        $start = microtime(true);

        if (!is_file($localPath) || !is_readable($localPath)) {
            return SftpTransferResult::failure($localPath, $remotePath, 'Local file not readable.');
        }

        $content = @file_get_contents($localPath);
        if ($content === false) {
            return SftpTransferResult::failure($localPath, $remotePath, 'Failed to read local file.');
        }

        $tempPath = $remotePath . '.tmp.' . bin2hex(random_bytes(6));

        try {
            $sftp = $this->getSftp();

            if (!$sftp->put($tempPath, $content)) {
                $err = $sftp->getLastSFTPError();
                return SftpTransferResult::failure(
                    $localPath,
                    $remotePath,
                    'SFTP put failed: ' . ($err !== false && $err !== '' ? $err : 'no error reported'),
                );
            }

            $sftp->exec(sprintf(
                'mv -f %s %s',
                escapeshellarg($tempPath),
                escapeshellarg($remotePath),
            ));

            if ($sftp->getExitStatus() !== 0) {
                $sftp->delete($tempPath);
                return SftpTransferResult::failure(
                    $localPath,
                    $remotePath,
                    'Atomic rename failed: ' . (string) $sftp->getStdError(),
                );
            }

            return SftpTransferResult::success(
                $localPath,
                $remotePath,
                strlen($content),
                microtime(true) - $start,
            );
        } catch (\Throwable $e) {
            return SftpTransferResult::failure($localPath, $remotePath, $e->getMessage());
        }
    }

    /**
     * Download a remote file to a local path atomically (temp + rename).
     */
    public function download(string $remotePath, string $localPath): SftpTransferResult
    {
        if ($this->config === null) {
            return SftpTransferResult::failure($remotePath, $localPath, 'Not connected.');
        }

        $start = microtime(true);
        $tempLocal = $localPath . '.tmp.' . bin2hex(random_bytes(6));

        try {
            $sftp = $this->getSftp();

            if (!$sftp->get($remotePath, $tempLocal)) {
                @unlink($tempLocal);
                $err = $sftp->getLastSFTPError();
                return SftpTransferResult::failure(
                    $remotePath,
                    $localPath,
                    'SFTP get failed: ' . ($err !== false && $err !== '' ? $err : 'no error reported'),
                );
            }

            if (!@rename($tempLocal, $localPath)) {
                @unlink($tempLocal);
                return SftpTransferResult::failure($remotePath, $localPath, 'Local rename failed.');
            }

            $size = @filesize($localPath);

            return SftpTransferResult::success(
                $remotePath,
                $localPath,
                $size !== false ? $size : 0,
                microtime(true) - $start,
            );
        } catch (\Throwable $e) {
            @unlink($tempLocal);
            return SftpTransferResult::failure($remotePath, $localPath, $e->getMessage());
        }
    }

    /**
     * Read a remote file into memory, refusing files larger than $maxBytes.
     * The size check happens via SFTP stat before any bytes are transferred.
     */
    public function readFile(string $remotePath, int $maxBytes): string
    {
        if ($this->config === null) {
            throw new ConnectionException('Not connected.');
        }

        $sftp = $this->getSftp();
        $size = $sftp->filesize($remotePath);

        if ($size === false) {
            $err = $sftp->getLastSFTPError();
            throw new LinOpsException(sprintf(
                'Cannot stat remote file %s: %s',
                $remotePath,
                $err !== false && $err !== '' ? $err : 'no error reported',
            ));
        }

        if ($size > $maxBytes) {
            throw new LinOpsException(sprintf(
                'Remote file %s is %d bytes, exceeding max %d bytes.',
                $remotePath,
                $size,
                $maxBytes,
            ));
        }

        $content = $sftp->get($remotePath);
        if ($content === false) {
            $err = $sftp->getLastSFTPError();
            throw new LinOpsException(sprintf(
                'Failed to read remote file %s: %s',
                $remotePath,
                $err !== false && $err !== '' ? $err : 'no error reported',
            ));
        }

        return $content;
    }
}
