<?php

declare(strict_types=1);

namespace AfazTech\LinOps;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\ControlPanel\ControlPanelDetector;
use AfazTech\LinOps\Exception\LinOpsException;
use AfazTech\LinOps\Firewall\FirewallDetector;
use AfazTech\LinOps\Network\NetworkInspector;
use AfazTech\LinOps\Network\NetworkMonitor;
use AfazTech\LinOps\Result\CommandResult;
use AfazTech\LinOps\Result\ControlPanelInfo;
use AfazTech\LinOps\Result\CpuLoadInfo;
use AfazTech\LinOps\Result\DiskInfo;
use AfazTech\LinOps\Result\DnsConfigInfo;
use AfazTech\LinOps\Result\FirewallInfo;
use AfazTech\LinOps\Result\HardwareInfo;
use AfazTech\LinOps\Result\HostInfo;
use AfazTech\LinOps\Result\InterfaceTrafficInfo;
use AfazTech\LinOps\Result\ListeningPort;
use AfazTech\LinOps\Result\LoginRecord;
use AfazTech\LinOps\Result\MemoryInfo;
use AfazTech\LinOps\Result\NetworkAddressInfo;
use AfazTech\LinOps\Result\ProcessInfo;
use AfazTech\LinOps\Result\RouteInfo;
use AfazTech\LinOps\Result\ServiceRestartResult;
use AfazTech\LinOps\Result\ServiceStatus;
use AfazTech\LinOps\Result\SftpTransferResult;
use AfazTech\LinOps\Service\ServiceInspector;
use AfazTech\LinOps\Streaming\LocalStreamingRunner;
use AfazTech\LinOps\Streaming\SshStreamingRunner;
use AfazTech\LinOps\Streaming\StreamingRunnerInterface;
use AfazTech\LinOps\System\HardwareInspector;
use AfazTech\LinOps\System\LogInspector;
use AfazTech\LinOps\System\ProcessInspector;
use AfazTech\LinOps\System\RebootManager;
use AfazTech\LinOps\System\ResourceMonitor;
use AfazTech\LinOps\System\SystemInspector;
use AfazTech\LinOps\Transport\WorkerPoolTransport;

final class LinOps
{
    public const DEFAULT_READ_MAX_BYTES = 10 * 1024 * 1024;

    private readonly WorkerPoolTransport $transport;
    private readonly CommandExecutor $executor;
    private readonly SystemInspector $systemInspector;
    private readonly ResourceMonitor $resourceMonitor;
    private readonly NetworkInspector $networkInspector;
    private readonly NetworkMonitor $networkMonitor;
    private readonly ControlPanelDetector $controlPanelDetector;
    private readonly ServiceInspector $serviceInspector;
    private readonly RebootManager $rebootManager;
    private readonly HardwareInspector $hardwareInspector;
    private readonly ProcessInspector $processInspector;
    private readonly LogInspector $logInspector;
    private readonly FirewallDetector $firewallDetector;
    private ?StreamingRunnerInterface $streamingRunner = null;

    public function __construct(
        private readonly ServerConfig $config,
        ?WorkerPoolTransport $transport = null,
    ) {
        $this->transport = $transport ?? new WorkerPoolTransport(maxWorkers: 1);
        $this->executor = new CommandExecutor($this->transport);
        $this->systemInspector = new SystemInspector($this->executor);
        $this->resourceMonitor = new ResourceMonitor($this->executor);
        $this->networkInspector = new NetworkInspector($this->executor);
        $this->networkMonitor = new NetworkMonitor($this->executor);
        $this->controlPanelDetector = new ControlPanelDetector($this->executor);
        $this->serviceInspector = new ServiceInspector($this->executor);
        $this->rebootManager = new RebootManager($this->executor, $this->systemInspector);
        $this->hardwareInspector = new HardwareInspector($this->executor);
        $this->processInspector = new ProcessInspector($this->executor);
        $this->logInspector = new LogInspector($this->executor, $this);
        $this->firewallDetector = new FirewallDetector($this->executor);
    }

    // ─── System ────────────────────────────────────────────────────────────

    public function getHostInfo(): HostInfo
    {
        return $this->systemInspector->getHostInfo($this->config);
    }

    public function getCpuLoad(float $sampleInterval = 1.0): CpuLoadInfo
    {
        return $this->resourceMonitor->getCpuLoad($this->config, $sampleInterval);
    }

    public function getMemory(): MemoryInfo
    {
        return $this->resourceMonitor->getMemory($this->config);
    }

    /**
     * @param list<string>|null $mountPoints
     * @return list<DiskInfo>
     */
    public function getDisks(?array $mountPoints = null, bool $includePseudo = false): array
    {
        return $this->resourceMonitor->getDisks($this->config, $mountPoints, $includePseudo);
    }

    /**
     * @param list<string>|null $mountPoints
     * @return list<array<string, mixed>>
     */
    public function getDiskUsage(?array $mountPoints = null, bool $includePseudo = false): array
    {
        return array_map(
            static fn (DiskInfo $d): array => [
                'filesystem' => $d->filesystem,
                'mount' => $d->mountPoint,
                'type' => $d->type,
                'size' => $d->totalBytes,
                'used' => $d->usedBytes,
                'available' => $d->availableBytes,
                'use_percent' => $d->usagePercent,
                'inodes' => [
                    'total' => $d->inodeTotal,
                    'used' => $d->inodeUsed,
                    'available' => $d->inodeAvailable,
                    'use_percent' => $d->inodeUsagePercent,
                ],
            ],
            $this->resourceMonitor->getDisks($this->config, $mountPoints, $includePseudo),
        );
    }

    public function getHardwareInfo(): HardwareInfo
    {
        return $this->hardwareInspector->inspect($this->config);
    }

    public function needsReboot(): bool
    {
        return $this->rebootManager->needsReboot($this->config);
    }

    public function reboot(): ServiceRestartResult
    {
        $result = $this->executor->executeSingle($this->config, 'reboot', 10.0);

        return new ServiceRestartResult(
            service: 'reboot',
            success: $result->success,
            exitCode: $result->exitCode,
            stdout: $result->stdout,
            stderr: $result->stderr,
            error: $result->error,
        );
    }

    // ─── Network ───────────────────────────────────────────────────────────

    /** @return list<NetworkAddressInfo> */
    public function getAddresses(): array
    {
        return $this->networkInspector->getAddresses($this->config);
    }

    /** @return list<RouteInfo> */
    public function getRoutes(): array
    {
        return $this->networkInspector->getRoutes($this->config);
    }

    public function getDnsConfig(): DnsConfigInfo
    {
        return $this->networkInspector->getDnsConfig($this->config);
    }

    /**
     * @param list<string>|null $interfaces
     * @return list<InterfaceTrafficInfo>
     */
    public function getTraffic(?array $interfaces = null, float $sampleInterval = 1.0): array
    {
        return $this->networkMonitor->getTraffic($this->config, $interfaces, $sampleInterval);
    }

    // ─── Control panels & services ─────────────────────────────────────────

    /** @return list<ControlPanelInfo> */
    public function detectControlPanels(): array
    {
        return $this->controlPanelDetector->detect($this->config);
    }

    /** @return list<ServiceStatus> */
    public function listServices(?string $pattern = null): array
    {
        return $this->serviceInspector->listServices($this->config, $pattern);
    }

    public function getServiceStatus(string $service): ServiceStatus
    {
        return $this->serviceInspector->getStatus($this->config, $service);
    }

    public function restartService(string $service): ServiceRestartResult
    {
        return $this->serviceInspector->restart($this->config, $service);
    }

    // ─── Firewall ──────────────────────────────────────────────────────────

    /**
     * Returns all active firewalls sorted by precedence (controlling first).
     *
     * @return list<FirewallInfo>
     */
    public function detectFirewalls(): array
    {
        return $this->firewallDetector->detect($this->config);
    }

    /**
     * Returns the controlling firewall (the first active one by precedence),
     * or null if none was detected.
     */
    public function getActiveFirewall(): ?FirewallInfo
    {
        return $this->firewallDetector->getActive($this->config);
    }

    // ─── Processes & ports ─────────────────────────────────────────────────

    /** @return list<ProcessInfo> */
    public function listProcesses(): array
    {
        return $this->processInspector->listProcesses($this->config);
    }

    /** @return list<ListeningPort> */
    public function listListeningPorts(): array
    {
        return $this->processInspector->listListeningPorts($this->config);
    }

    // ─── Logs & logins ─────────────────────────────────────────────────────

    /** @return list<LoginRecord> */
    public function lastLogins(int $limit = 20): array
    {
        return $this->logInspector->lastLogins($this->config, $limit);
    }

    /** @return list<LoginRecord> */
    public function getFailedLogins(int $limit = 20): array
    {
        return $this->logInspector->getFailedLogins($this->config, $limit);
    }

    /**
     * Follow a remote file with tail -f for $duration seconds. Returns
     * whatever was written during the window. The command is killed when
     * $duration elapses.
     */
    public function tailFile(string $path, float $duration = 60.0): string
    {
        return $this->logInspector->tailFile($this->config, $path, $duration);
    }

    /**
     * Follow journalctl -f for a unit for $duration seconds. Returns
     * whatever was written during the window.
     */
    public function journalFollow(string $unit, float $duration = 60.0): string
    {
        return $this->logInspector->journalFollow($this->config, $unit, $duration);
    }

    // ─── SFTP ──────────────────────────────────────────────────────────────

    /**
     * Upload a local file to the remote host. Writes to a temp file next to
     * the destination, then renames atomically.
     *
     * In local mode this is a plain file copy.
     */
    public function upload(string $localPath, string $remotePath): SftpTransferResult
    {
        if ($this->config->isLocal()) {
            return $this->localCopy($localPath, $remotePath);
        }

        return $this->transport->upload($this->config, $localPath, $remotePath);
    }

    /**
     * Download a remote file. In SSH mode uses SFTP and writes atomically.
     * In local mode this is a plain file copy.
     */
    public function download(string $remotePath, string $localPath): SftpTransferResult
    {
        if ($this->config->isLocal()) {
            return $this->localCopy($remotePath, $localPath);
        }

        return $this->transport->download($this->config, $remotePath, $localPath);
    }

    /**
     * Read a remote file into memory, refusing files larger than $maxBytes.
     * The size check happens before any bytes are transferred.
     */
    public function readFile(
        string $remotePath,
        int $maxBytes = self::DEFAULT_READ_MAX_BYTES,
    ): string {
        if ($this->config->isLocal()) {
            return $this->localReadFile($remotePath, $maxBytes);
        }

        return $this->transport->readFile($this->config, $remotePath, $maxBytes);
    }

    // ─── Command execution ─────────────────────────────────────────────────

    public function execute(string $command, ?float $timeout = null): CommandResult
    {
        return $this->executor->executeSingle($this->config, $command, $timeout);
    }

    /** @param list<string> $commands @return list<CommandResult> */
    public function executeMany(array $commands, ?float $timeout = null): array
    {
        return $this->executor->execute($this->config, $commands, $timeout);
    }

    /**
     * Run a command over SSH (or locally) with a selectable output mode.
     *
     *   CAPTURE     - buffer stdout/stderr and return them in the result.
     *   STREAM      - stream chunks to $onChunk; stdout/stderr are null.
     *   INTERACTIVE - stream chunks and forward local STDIN (or the supplied
     *                 $inputProvider) to the process.
     *
     * When $sudo is enabled on the config, the command is wrapped with
     * `sudo -n` before execution in every mode except openShell().
     */
    public function executeCommand(
        string $command,
        OutputMode $mode = OutputMode::CAPTURE,
        ?callable $onChunk = null,
        ?callable $inputProvider = null,
        ?float $timeout = null,
        bool $allowFallback = false,
    ): CommandResult {
        if ($mode === OutputMode::CAPTURE) {
            // CommandExecutor handles sudo wrapping itself.
            return $this->executor->executeSingle($this->config, $command, $timeout);
        }

        if ($onChunk === null) {
            throw new \InvalidArgumentException(
                'onChunk is required for OutputMode::STREAM and OutputMode::INTERACTIVE.'
            );
        }

        $start = microtime(true);
        $effectiveCommand = $this->config->wrapCommand($command);

        $restoreStdinBlocking = false;
        if ($mode === OutputMode::INTERACTIVE) {
            $inputProvider = $this->resolveInputProvider($inputProvider, $restoreStdinBlocking);
        } else {
            $inputProvider = null;
        }

        try {
            $exitCode = $this->streamingRunner()->stream(
                $this->config,
                $effectiveCommand,
                $onChunk,
                $inputProvider,
                $timeout,
                $allowFallback,
            );
        } finally {
            if ($restoreStdinBlocking && defined('STDIN') && is_resource(STDIN)) {
                stream_set_blocking(STDIN, true);
            }
        }

        return new CommandResult(
            command: $command,
            success: $exitCode === 0,
            stdout: null,
            stderr: null,
            exitCode: $exitCode,
            duration: microtime(true) - $start,
        );
    }

    /**
     * Open a pure interactive shell session (no command) as the SSH login
     * user. Use `sudo -i` inside the shell for elevation.
     */
    public function openShell(
        ?callable $onChunk = null,
        ?callable $inputProvider = null,
        ?float $timeout = null,
    ): int {
        if ($onChunk === null) {
            $onChunk = static function (string $stream, string $data): void {
                $target = $stream === 'stderr' ? STDERR : STDOUT;
                fwrite($target, $data);
                fflush($target);
            };
        }

        $restoreStdinBlocking = false;
        $inputProvider = $this->resolveInputProvider($inputProvider, $restoreStdinBlocking);

        try {
            return $this->streamingRunner()->openShell(
                $this->config,
                $onChunk,
                $inputProvider,
                $timeout,
            );
        } finally {
            if ($restoreStdinBlocking && defined('STDIN') && is_resource(STDIN)) {
                stream_set_blocking(STDIN, true);
            }
        }
    }

    // ─── Local mode helpers ────────────────────────────────────────────────

    private function localCopy(string $src, string $dst): SftpTransferResult
    {
        $start = microtime(true);

        if (!is_file($src) || !is_readable($src)) {
            return SftpTransferResult::failure($src, $dst, 'Source not readable.');
        }

        $temp = $dst . '.tmp.' . bin2hex(random_bytes(6));
        if (!@copy($src, $temp)) {
            @unlink($temp);
            return SftpTransferResult::failure($src, $dst, 'Copy failed.');
        }

        if (!@rename($temp, $dst)) {
            @unlink($temp);
            return SftpTransferResult::failure($src, $dst, 'Rename failed.');
        }

        $size = @filesize($dst);

        return SftpTransferResult::success(
            $src,
            $dst,
            $size !== false ? $size : 0,
            microtime(true) - $start,
        );
    }

    private function localReadFile(string $path, int $maxBytes): string
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new LinOpsException(sprintf('File not found: %s', $path));
        }

        if ($size > $maxBytes) {
            throw new LinOpsException(sprintf(
                'File %s is %d bytes, exceeding max %d bytes.',
                $path,
                $size,
                $maxBytes,
            ));
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new LinOpsException(sprintf('Failed to read file: %s', $path));
        }

        return $content;
    }

    // ─── Internals ─────────────────────────────────────────────────────────

    private function resolveInputProvider(?callable $inputProvider, bool &$restoreStdinBlocking): ?callable
    {
        if ($inputProvider !== null) {
            return $inputProvider;
        }

        if (!defined('STDIN') || !is_resource(STDIN) || !@stream_isatty(STDIN)) {
            return null;
        }

        $restoreStdinBlocking = (stream_get_meta_data(STDIN)['blocked'] ?? true);
        stream_set_blocking(STDIN, false);

        return static function (): ?string {
            $data = fread(STDIN, 4096);
            return ($data !== false && $data !== '') ? $data : null;
        };
    }

    private function streamingRunner(): StreamingRunnerInterface
    {
        if ($this->streamingRunner !== null) {
            return $this->streamingRunner;
        }

        $this->streamingRunner = $this->config->isLocal()
            ? new LocalStreamingRunner()
            : new SshStreamingRunner();

        return $this->streamingRunner;
    }

    public function close(): void
    {
        $this->transport->closeAll();
        $this->streamingRunner = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
