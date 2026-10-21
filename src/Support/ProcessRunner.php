<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Support;

use AfazTech\LinOps\Result\CommandResult;

/**
 * Synchronous process runner used inside worker processes.
 * It is intentionally blocking; workers run in isolated OS processes.
 */
final class ProcessRunner
{
    public static function run(string $command, float $timeout, int $maxOutputBytes = 1048576): CommandResult
    {
        $start = microtime(true);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return new CommandResult(
                command: $command,
                success: false,
                error: 'Failed to start process.',
                duration: microtime(true) - $start,
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = $start + $timeout;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (strlen($stdout) > $maxOutputBytes) {
                $stdout = substr($stdout, 0, $maxOutputBytes);
            }
            if (strlen($stderr) > $maxOutputBytes) {
                $stderr = substr($stderr, 0, $maxOutputBytes);
            }

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                $exitCode = -1;
                break;
            }

            usleep(10000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return new CommandResult(
            command: $command,
            success: !$timedOut && $exitCode === 0,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            duration: microtime(true) - $start,
            error: $timedOut ? 'Command timed out.' : null,
        );
    }
}
