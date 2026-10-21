<?php

declare(strict_types=1);

namespace AfazTech\LinOps;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\OperationResult;
use AfazTech\LinOps\Transport\WorkerPoolTransport;
use Amp\Cancellation;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Semaphore;
use function Amp\async;
use function Amp\Future\await;

/**
 * Multi-server executor with bounded concurrency.
 *
 * collect() returns all results in input order by default.
 * stream() yields results as they complete (completion order).
 */
final class ServerPool
{
    private readonly WorkerPoolTransport $transport;
    private readonly CommandExecutor $executor;
    private readonly Semaphore $semaphore;

    public function __construct(
        int $concurrency = 10,
        ?WorkerPoolTransport $transport = null,
    ) {
        $this->semaphore = new LocalSemaphore(max(1, $concurrency));
        $this->transport = $transport ?? new WorkerPoolTransport(maxWorkers: max(1, $concurrency));
        $this->executor = new CommandExecutor($this->transport);
    }

    /**
     * Execute a callback for each server with bounded concurrency.
     *
     * @template T
     * @param list<ServerConfig> $servers
     * @param callable(ServerConfig): T $operation
     * @return list<OperationResult>
     */
    public function collect(
        array $servers,
        callable $operation,
        ?Cancellation $cancellation = null,
        bool $preserveOrder = true,
    ): array {
        $futures = [];

        foreach ($servers as $server) {
            $futures[] = async(function () use ($server, $operation, $cancellation): OperationResult {
                $lock = $this->semaphore->acquire($cancellation);
                $startedAt = new \DateTimeImmutable();
                $start = microtime(true);

                try {
                    $data = $operation($server);
                    return OperationResult::success(
                        serverIdentifier: $server->getIdentifier(),
                        operation: 'callback',
                        data: $data,
                        duration: microtime(true) - $start,
                        startedAt: $startedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                } catch (\Throwable $e) {
                    return OperationResult::failure(
                        serverIdentifier: $server->getIdentifier(),
                        operation: 'callback',
                        errorType: $e::class,
                        errorMessage: $e->getMessage(),
                        duration: microtime(true) - $start,
                        startedAt: $startedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                } finally {
                    $lock->release();
                }
            });
        }

        /** @var array<int, OperationResult> $results */
        $results = await($futures, $cancellation);

        if ($preserveOrder) {
            // Future\await returns results in completion order but keyed by
            // the original input index; ksort restores input order.
            ksort($results);
        }

        return array_values($results);
    }

    /**
     * Stream results as they complete, without waiting for the whole batch.
     *
     * @param list<ServerConfig> $servers
     * @return \Generator<int, OperationResult>
     */
    public function stream(
        array $servers,
        callable $operation,
        ?Cancellation $cancellation = null,
    ): \Generator {
        $futures = [];

        foreach ($servers as $index => $server) {
            $futures[$index] = async(function () use ($server, $operation, $cancellation): OperationResult {
                $lock = $this->semaphore->acquire($cancellation);
                $startedAt = new \DateTimeImmutable();
                $start = microtime(true);

                try {
                    $data = $operation($server);
                    return OperationResult::success(
                        serverIdentifier: $server->getIdentifier(),
                        operation: 'callback',
                        data: $data,
                        duration: microtime(true) - $start,
                        startedAt: $startedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                } catch (\Throwable $e) {
                    return OperationResult::failure(
                        serverIdentifier: $server->getIdentifier(),
                        operation: 'callback',
                        errorType: $e::class,
                        errorMessage: $e->getMessage(),
                        duration: microtime(true) - $start,
                        startedAt: $startedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                } finally {
                    $lock->release();
                }
            });
        }

        while ($futures !== []) {
            $completed = null;
            foreach ($futures as $index => $future) {
                if ($future->isComplete()) {
                    $completed = $index;
                    break;
                }
            }

            if ($completed === null) {
                \Amp\Future\awaitAny($futures, $cancellation);
                continue;
            }

            $future = $futures[$completed];
            unset($futures[$completed]);
            yield $completed => $future->await($cancellation);
        }
    }

    /**
     * Convenience wrapper for command execution across servers.
     *
     * @param list<ServerConfig> $servers
     * @param list<string> $commands
     * @return list<OperationResult>
     */
    public function executeCommands(
        array $servers,
        array $commands,
        ?Cancellation $cancellation = null,
        bool $preserveOrder = true,
    ): array {
        return $this->collect(
            $servers,
            function (ServerConfig $server) use ($commands, $cancellation): mixed {
                return $this->executor->execute($server, $commands, null, $cancellation);
            },
            $cancellation,
            $preserveOrder,
        );
    }

    public function close(): void
    {
        $this->transport->closeAll();
    }

    public function __destruct()
    {
        $this->close();
    }
}
