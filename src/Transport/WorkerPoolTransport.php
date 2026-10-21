<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Transport;

use AfazTech\LinOps\Exception\CommandFailedException;
use AfazTech\LinOps\Exception\WorkerException;
use AfazTech\LinOps\Result\CommandResult;
use AfazTech\LinOps\Result\SftpTransferResult;
use AfazTech\LinOps\ServerConfig;
use Amp\Cancellation;
use Amp\Process\Process;
use Amp\Sync\LocalMutex;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Semaphore;

/**
 * Asynchronous transport that delegates blocking phpseclib work to isolated
 * worker processes. At most $maxWorkers worker processes exist at any time.
 *
 * Supports both SSH exec commands and SFTP file operations. Per-server
 * mutexes serialise requests to the same worker.
 */
final class WorkerPoolTransport
{
    /** @var array<string, WorkerHandle> */
    private array $workers = [];

    /** @var array<string, true> */
    private array $active = [];

    /** @var array<string, LocalMutex> */
    private array $mutexes = [];

    /** @var list<string> */
    private array $lru = [];

    public function __construct(
        private readonly int $maxWorkers = 10,
        private readonly string $phpBinary = PHP_BINARY,
        private readonly ?string $workerScript = null,
        private readonly Semaphore $semaphore = new LocalSemaphore(10),
    ) {
    }

    /**
     * @param list<string> $commands
     * @return list<CommandResult>
     */
    public function execute(
        ServerConfig $config,
        array $commands,
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): array {
        $decoded = $this->sendRequest($config, [
            'type' => 'execute',
            'commands' => $commands,
            'timeout' => $timeout,
        ], $cancellation);

        return array_map(
            static fn (array $row): CommandResult => CommandResult::fromArray($row),
            (array) ($decoded['results'] ?? []),
        );
    }

    public function upload(ServerConfig $config, string $localPath, string $remotePath): SftpTransferResult
    {
        try {
            $decoded = $this->sendRequest($config, [
                'type' => 'sftp_upload',
                'localPath' => $localPath,
                'remotePath' => $remotePath,
            ]);

            return SftpTransferResult::success(
                $localPath,
                $remotePath,
                (int) ($decoded['bytes'] ?? 0),
            );
        } catch (\Throwable $e) {
            return SftpTransferResult::failure($localPath, $remotePath, $e->getMessage());
        }
    }

    public function download(ServerConfig $config, string $remotePath, string $localPath): SftpTransferResult
    {
        try {
            $decoded = $this->sendRequest($config, [
                'type' => 'sftp_download',
                'remotePath' => $remotePath,
                'localPath' => $localPath,
            ]);

            return SftpTransferResult::success(
                $remotePath,
                $localPath,
                (int) ($decoded['bytes'] ?? 0),
            );
        } catch (\Throwable $e) {
            return SftpTransferResult::failure($remotePath, $localPath, $e->getMessage());
        }
    }

    public function readFile(ServerConfig $config, string $remotePath, int $maxBytes): string
    {
        $decoded = $this->sendRequest($config, [
            'type' => 'sftp_read',
            'remotePath' => $remotePath,
            'maxBytes' => $maxBytes,
        ]);

        $data = base64_decode((string) ($decoded['data'] ?? ''), true);
        if ($data === false) {
            throw new CommandFailedException('Worker returned invalid base64 data.');
        }

        return $data;
    }

    /**
     * Send a single request to the worker pool and return the decoded
     * JSON response. Serialises requests per connection key, bounds total
     * concurrency via the semaphore, and invalidates the worker on any
     * transport-level error.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendRequest(
        ServerConfig $config,
        array $payload,
        ?Cancellation $cancellation = null,
    ): array {
        $semLock = $this->semaphore->acquire($cancellation);
        $key = $config->getConnectionKey();
        $mutex = $this->mutexes[$key] ??= new LocalMutex();
        $mutexLock = $mutex->acquire($cancellation);
        $this->active[$key] = true;

        try {
            $handle = $this->acquireWorker($config);

            $request = json_encode(
                $payload + ['config' => $config->toArray()],
                JSON_THROW_ON_ERROR,
            ) . "\n";

            $handle->process->getStdin()->write($request);

            $line = $handle->readLine($cancellation);
            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                $handle->invalidate();
                throw new WorkerException('Worker returned invalid JSON.');
            }

            if (($decoded['ok'] ?? false) !== true) {
                $handle->invalidate();
                throw new WorkerException((string) ($decoded['error'] ?? 'Worker error'));
            }

            $this->touch($key);

            return $decoded;
        } finally {
            unset($this->active[$key]);
            $mutexLock->release();
            $semLock->release();
        }
    }

    private function acquireWorker(ServerConfig $config): WorkerHandle
    {
        $key = $config->getConnectionKey();

        if (isset($this->workers[$key]) && $this->workers[$key]->isAlive()) {
            $this->touch($key);
            return $this->workers[$key];
        }

        if (count($this->workers) >= $this->maxWorkers) {
            $this->evictLeastRecentlyUsed();
        }

        $handle = $this->spawnWorker();
        $this->workers[$key] = $handle;
        $this->touch($key);

        return $handle;
    }

    private function spawnWorker(): WorkerHandle
    {
        $script = $this->workerScript
            ?? dirname(__DIR__, 2) . '/src/Transport/Worker/worker.php';

        $process = Process::start([$this->phpBinary, $script]);
        $process->getStdin()->reference();

        return new WorkerHandle($process);
    }

    private function touch(string $key): void
    {
        $this->lru = array_values(array_filter($this->lru, static fn (string $k) => $k !== $key));
        $this->lru[] = $key;
    }

    private function evictLeastRecentlyUsed(): void
    {
        foreach ($this->lru as $key) {
            if (isset($this->active[$key])) {
                continue;
            }
            if (!isset($this->workers[$key])) {
                continue;
            }

            $this->workers[$key]->close();
            unset($this->workers[$key]);
            $this->lru = array_values(array_filter(
                $this->lru,
                static fn (string $k): bool => $k !== $key,
            ));
            return;
        }
    }

    public function closeAll(): void
    {
        foreach ($this->workers as $worker) {
            $worker->close();
        }

        $this->workers = [];
        $this->lru = [];
        $this->active = [];
        $this->mutexes = [];
    }

    public function __destruct()
    {
        $this->closeAll();
    }
}
