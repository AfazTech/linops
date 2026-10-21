<?php

declare(strict_types=1);

namespace AfazTech\LinOps;

use AfazTech\LinOps\Exception\InvalidConfigurationException;
use AfazTech\LinOps\Support\HostKeyPolicy;

/**
 * Immutable server connection configuration.
 *
 * Two modes are supported:
 *   - SSH (default): requires host, username, and password or private key.
 *   - LOCAL: runs commands directly on the host executing this process.
 *
 * When $sudo is true, every command dispatched by CommandExecutor and
 * executeCommand(INTERACTIVE/STREAM) is wrapped with `sudo -n`, which
 * requires a NOPASSWD entry in sudoers. If $sudoUser is set, commands run
 * as that user; otherwise they run as root.
 */
final class ServerConfig
{
    /** @var array<string, mixed> */
    public readonly array $transportOptions;

    /**
     * @param array<string, mixed> $transportOptions
     */
    public function __construct(
        public readonly string $host = 'localhost',
        public readonly int $port = 22,
        public readonly string $username = 'root',
        public readonly ?string $password = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $privateKeyPassphrase = null,
        public readonly float $connectionTimeout = 10.0,
        public readonly float $commandTimeout = 30.0,
        public readonly HostKeyPolicy $hostKeyPolicy = HostKeyPolicy::STRICT,
        public readonly ?string $knownHostsFile = null,
        public readonly ?string $label = null,
        array $transportOptions = [],
        public readonly ConnectionMode $mode = ConnectionMode::SSH,
        public readonly bool $sudo = false,
        public readonly ?string $sudoUser = null,
    ) {
        $this->transportOptions = $transportOptions;
        $this->validate();
    }

    public static function local(
        float $connectionTimeout = 10.0,
        float $commandTimeout = 30.0,
        ?string $label = 'local',
    ): self {
        return new self(
            host: 'localhost',
            port: 22,
            username: '',
            password: null,
            privateKey: null,
            privateKeyPassphrase: null,
            connectionTimeout: $connectionTimeout,
            commandTimeout: $commandTimeout,
            hostKeyPolicy: HostKeyPolicy::INSECURE,
            knownHostsFile: null,
            label: $label,
            mode: ConnectionMode::LOCAL,
        );
    }

    public function isLocal(): bool
    {
        return $this->mode === ConnectionMode::LOCAL;
    }

    /**
     * Wrap a shell command with sudo if $sudo is enabled.
     *
     * Uses `sudo -n` so the command fails immediately (instead of blocking)
     * when the sudoers entry does not have NOPASSWD.
     */
    public function wrapCommand(string $command): string
    {
        if (!$this->sudo) {
            return $command;
        }

        if ($this->sudoUser !== null && $this->sudoUser !== '') {
            return sprintf('sudo -n -u %s %s', escapeshellarg($this->sudoUser), $command);
        }

        return 'sudo -n ' . $command;
    }

    private function validate(): void
    {
        if ($this->connectionTimeout <= 0) {
            throw new InvalidConfigurationException('Connection timeout must be greater than zero.');
        }

        if ($this->commandTimeout <= 0) {
            throw new InvalidConfigurationException('Command timeout must be greater than zero.');
        }

        if ($this->sudoUser !== null && trim($this->sudoUser) === '') {
            throw new InvalidConfigurationException('sudoUser must not be empty when set.');
        }

        if ($this->mode === ConnectionMode::LOCAL) {
            return;
        }

        if (trim($this->host) === '') {
            throw new InvalidConfigurationException('Host must not be empty.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidConfigurationException('Port must be between 1 and 65535.');
        }

        if (trim($this->username) === '') {
            throw new InvalidConfigurationException('Username must not be empty.');
        }

        if ($this->password === null && $this->privateKey === null) {
            throw new InvalidConfigurationException(
                'Either password or privateKey must be provided.'
            );
        }

        if ($this->password !== null && $this->privateKey !== null) {
            throw new InvalidConfigurationException(
                'Provide either password or privateKey, not both.'
            );
        }

        if ($this->privateKeyPassphrase !== null && $this->privateKey === null) {
            throw new InvalidConfigurationException(
                'Private key passphrase requires a private key.'
            );
        }

        if ($this->privateKey !== null && trim($this->privateKey) === '') {
            throw new InvalidConfigurationException('Private key must not be empty.');
        }
    }

    public function getIdentifier(): string
    {
        if ($this->label !== null && $this->label !== '') {
            return $this->label;
        }

        if ($this->mode === ConnectionMode::LOCAL) {
            return 'local';
        }

        return sprintf('%s@%s:%d', $this->username, $this->host, $this->port);
    }

    public function getConnectionKey(): string
    {
        return hash('sha256', sprintf(
            '%s:%d:%s:%s',
            $this->host,
            $this->port,
            $this->username,
            $this->mode->value,
        ));
    }

    public function getResolvedKnownHostsFile(): string
    {
        if ($this->knownHostsFile !== null) {
            return $this->knownHostsFile;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $home = sys_get_temp_dir();
        }

        return rtrim($home, '/') . '/.ssh/known_hosts';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'privateKey' => $this->privateKey,
            'privateKeyPassphrase' => $this->privateKeyPassphrase,
            'connectionTimeout' => $this->connectionTimeout,
            'commandTimeout' => $this->commandTimeout,
            'hostKeyPolicy' => $this->hostKeyPolicy->value,
            'knownHostsFile' => $this->knownHostsFile,
            'label' => $this->label,
            'transportOptions' => $this->transportOptions,
            'mode' => $this->mode->value,
            'sudo' => $this->sudo,
            'sudoUser' => $this->sudoUser,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            host: (string) ($data['host'] ?? 'localhost'),
            port: (int) ($data['port'] ?? 22),
            username: (string) ($data['username'] ?? 'root'),
            password: isset($data['password']) ? (string) $data['password'] : null,
            privateKey: isset($data['privateKey']) ? (string) $data['privateKey'] : null,
            privateKeyPassphrase: isset($data['privateKeyPassphrase'])
                ? (string) $data['privateKeyPassphrase']
                : null,
            connectionTimeout: (float) ($data['connectionTimeout'] ?? 10.0),
            commandTimeout: (float) ($data['commandTimeout'] ?? 30.0),
            hostKeyPolicy: HostKeyPolicy::from((string) ($data['hostKeyPolicy'] ?? 'strict')),
            knownHostsFile: isset($data['knownHostsFile']) ? (string) $data['knownHostsFile'] : null,
            label: isset($data['label']) ? (string) $data['label'] : null,
            transportOptions: is_array($data['transportOptions'] ?? null)
                ? $data['transportOptions']
                : [],
            mode: ConnectionMode::from((string) ($data['mode'] ?? 'ssh')),
            sudo: (bool) ($data['sudo'] ?? false),
            sudoUser: isset($data['sudoUser']) ? (string) $data['sudoUser'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password !== null ? '***' : null,
            'privateKey' => $this->privateKey !== null ? '***' : null,
            'privateKeyPassphrase' => $this->privateKeyPassphrase !== null ? '***' : null,
            'connectionTimeout' => $this->connectionTimeout,
            'commandTimeout' => $this->commandTimeout,
            'hostKeyPolicy' => $this->hostKeyPolicy->value,
            'knownHostsFile' => $this->knownHostsFile,
            'label' => $this->label,
            'mode' => $this->mode->value,
            'sudo' => $this->sudo,
            'sudoUser' => $this->sudoUser,
        ];
    }
}
