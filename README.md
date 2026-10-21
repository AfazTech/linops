# LinOps

asynchronous PHP library for Linux server management over SSH.

LinOps gives you a single, type-safe API for inspecting and operating Linux
servers — locally or over SSH — with first-class support for streaming output,
interactive sessions, SFTP transfers, control panel detection, firewall
detection, and running under `sudo`. Blocking phpseclib calls are isolated in
dedicated worker processes so your application's event loop stays responsive
under load.

## Features

- **SSH & local execution** through the same API (`ServerConfig::local()` for
  the current host, `ServerConfig` for remote).
- **Host-key verification before credentials are sent** (`STRICT`, `TOFU`,
  `INSECURE` policies).
- **Async multi-server pool** with bounded concurrency
  (`ServerPool::collect()` preserves input order, `ServerPool::stream()`
  yields results as they complete).
- **System inspection** — host info, CPU load, memory, disks with inode usage,
  hardware inventory (CPU, memory modules, disks, SMART health, BIOS,
  motherboard).
- **Network inspection** — addresses (IPv4/IPv6, subnet, broadcast, private
  flags), routes, DNS, interface traffic rates.
- **Service management** — list, status, and explicit restart.
- **Control-panel detection** — cPanel/WHM, Plesk, DirectAdmin, HestiaCP,
  ISPConfig, Virtualmin/Webmin, CloudPanel, CyberPanel, aaPanel, 1Panel,
  FASTPANEL, VestaCP, Sentora, Froxlor, Webuzo, Enhance.
- **Firewall detection** with precedence (CSF > firewalld > ufw > nftables >
  iptables), plus a `getActiveFirewall()` helper.
- **Process & port inspection** — `ps` and `ss`/`netstat`.
- **Log & login history** — `last`, `lastb`, `tail -f`, `journalctl -f` with a
  bounded follow window.
- **SFTP** — atomic uploads and downloads (temp + rename), plus size-guarded
  `readFile()`.
- **Three output modes for command execution** — `CAPTURE`, `STREAM`,
  `INTERACTIVE` — selected via `OutputMode`.
- **PTY-aware streaming** that forwards stdin to interactive prompts (e.g.
  `yum`'s `[y/N]`) and never splits the completion marker across chunks.
- **`openShell()`** for a pure interactive session, like `ssh user@host`.
- **Sudo support** via `ServerConfig(sudo: true, sudoUser: 'www-data')`, using
  `sudo -n` (requires NOPASSWD in sudoers).
- **Secrets are never leaked** — `__debugInfo()`, `getIdentifier()`, and
  `getConnectionKey()` redact passwords, keys, and passphrases.

## Requirements

- PHP 8.2 or newer
- Extensions: `ext-json`, `ext-filter`, `ext-hash`
- Composer
- A remote SSH server with an interactive shell (for streaming / PTY features)
  or SFTP subsystem enabled (for file transfers)

## Installation

```bash
composer require afaztech/linops
```

## Quick start

```php
<?php

use AfazTech\LinOps\LinOps;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\HostKeyPolicy;

$config = new ServerConfig(
    host: '10.0.0.10',
    port: 22,
    username: 'deploy',
    privateKey: '/home/deploy/.ssh/id_ed25519',
    hostKeyPolicy: HostKeyPolicy::TOFU, // store on first use
);

$linops = new LinOps($config);

$host = $linops->getHostInfo();
echo $host->hostname, ' / ', $host->distributionId, "\n";

$load = $linops->getCpuLoad(1.0);
echo 'CPU usage: ', $load->usagePercent, "%\n";

foreach ($linops->getDisks() as $disk) {
    printf("%s mounted at %s — %.1f%% used\n",
        $disk->filesystem, $disk->mountPoint, $disk->usagePercent);
}

$linops->close();
```

## Core concepts

### `ServerConfig`

Immutable configuration for one server (SSH or local). Validated in the
constructor: timeouts must be positive, credentials must be explicit, and
local mode skips SSH-specific validation.

```php
$config = new ServerConfig(
    host: '10.0.0.10',
    port: 22,
    username: 'root',
    password: 'secret',              // OR privateKey, not both
    connectionTimeout: 10.0,
    commandTimeout: 30.0,
    hostKeyPolicy: HostKeyPolicy::STRICT,
    knownHostsFile: null,            // defaults to ~/.ssh/known_hosts
    label: 'web-01',                 // used in results and logs
    sudo: false,                     // wrap commands with `sudo -n`
    sudoUser: null,                  // e.g. 'www-data'
);

// Local execution — no SSH, no credentials.
$local = ServerConfig::local();
```

**Host key policies**

- `STRICT` — the host key must already be in `known_hosts` and match exactly.
  This is the default and is what you want in production.
- `TOFU` — trust on first use: unknown hosts are written to `known_hosts`, and
  subsequent connections still enforce an exact match.
- `INSECURE` — do not verify. Only use for isolated test environments.
  `ServerConfig::local()` selects this automatically because there is no
  remote host key to verify.

**Sudo**

When `sudo: true` is set, every command dispatched through `CommandExecutor`
and through `executeCommand(STREAM|INTERACTIVE)` is wrapped as
`sudo -n <command>` (or `sudo -n -u <sudoUser> <command>`). `-n` means no
password prompt is ever attempted, so the command fails fast if the sudoers
entry lacks `NOPASSWD`. `openShell()` is **not** wrapped — use
`sudo -i` inside the shell if you need elevation.

### `LinOps`

A facade bound to a single `ServerConfig`. Lazily creates a
`WorkerPoolTransport` with one worker process; you can inject your own pool.

### `ServerPool`

Fans a callback out across many servers with bounded concurrency:

```php
use AfazTech\LinOps\ServerPool;

$pool = new ServerPool(concurrency: 20);

// Collect all results (input order preserved by default).
$results = $pool->collect($servers, function (ServerConfig $server) {
    return (new LinOps($server))->getHostInfo()->toArray();
});

// Stream results as they complete (completion order).
foreach ($pool->stream($servers, function (ServerConfig $server) {
    return (new LinOps($server))->getCpuLoad()->toArray();
}) as $index => $result) {
    if ($result->success) {
        printf("[%d] %s => %s\n", $index, $result->serverIdentifier, json_encode($result->data));
    }
}
```

## Inspecting a server

```php
// System
$linops->getHostInfo(): HostInfo
$linops->getCpuLoad(float $sampleInterval = 1.0): CpuLoadInfo
$linops->getMemory(): MemoryInfo
$linops->getDisks(?array $mountPoints = null, bool $includePseudo = false): list<DiskInfo>
$linops->getDiskUsage(...): list<array<string, mixed>>   // df-style raw bytes
$linops->getHardwareInfo(): HardwareInfo
$linops->needsReboot(): bool

// Network
$linops->getAddresses(): list<NetworkAddressInfo>
$linops->getRoutes(): list<RouteInfo>
$linops->getDnsConfig(): DnsConfigInfo
$linops->getTraffic(?array $interfaces = null, float $sampleInterval = 1.0): list<InterfaceTrafficInfo>

// Control panels & firewalls
$linops->detectControlPanels(): list<ControlPanelInfo>
$linops->detectFirewalls(): list<FirewallInfo>
$linops->getActiveFirewall(): ?FirewallInfo

// Processes & ports
$linops->listProcesses(): list<ProcessInfo>
$linops->listListeningPorts(): list<ListeningPort>

// Logs & logins
$linops->lastLogins(int $limit = 20): list<LoginRecord>
$linops->getFailedLogins(int $limit = 20): list<LoginRecord>
$linops->tailFile(string $path, float $duration = 60.0): string
$linops->journalFollow(string $unit, float $duration = 60.0): string

// Services
$linops->listServices(?string $pattern = null): list<ServiceStatus>
$linops->getServiceStatus(string $service): ServiceStatus
$linops->restartService(string $service): ServiceRestartResult
```

Every inspection method returns an immutable result object with a `toArray()`
helper. Failures are represented either by an empty list, or by fields such as
`supported: false` / `error: "..."` on the result — never by a thrown
exception unless the connection itself is broken.

## SFTP

```php
use AfazTech\LinOps\Result\SftpTransferResult;

// Upload (atomic: writes to <remote>.tmp.XXXX then mv -f).
$result = $linops->upload('/tmp/build.tar.gz', '/opt/app/build.tar.gz');
if (!$result->success) {
    fwrite(STDERR, $result->error . "\n");
} else {
    echo "Uploaded {$result->bytesTransferred} bytes in {$result->duration}s\n";
}

// Download (atomic: writes to <local>.tmp.XXXX then rename).
$linops->download('/var/log/syslog', '/tmp/syslog');

// Read into memory with a hard size cap (default 10 MiB).
$content = $linops->readFile('/etc/nginx/nginx.conf', 64 * 1024);
```

`readFile()` performs an SFTP `stat()` before transferring any bytes, so
oversized files raise a `LinOpsException` and never enter PHP memory.

In local mode, all three methods fall back to plain file copies and reads.

SFTP always uses its own SSH connection, separate from the exec channel.
Reusing a long-lived `SSH2` object for SFTP does not work reliably in
phpseclib3 and produces silent `"SFTP put failed"` errors — LinOps opens a
dedicated SFTP session lazily on first use, and verifies the host key before
authenticating there too.

## Command execution

```php
use AfazTech\LinOps\OutputMode;

// Blocking, returns full output.
$result = $linops->execute('uptime');
echo $result->stdout;

$results = $linops->executeMany(['uptime', 'id', 'df -h']);
```

For long-running or interactive commands, use `executeCommand()`:

```php
// CAPTURE — buffer and return.
$result = $linops->executeCommand('systemctl status nginx', OutputMode::CAPTURE);

// STREAM — real-time chunks; stdout/stderr in the result are null.
$linops->executeCommand(
    'journalctl -u nginx -f',
    OutputMode::STREAM,
    function (string $stream, string $data) {
        fwrite($stream === 'stderr' ? STDERR : STDOUT, $data);
    },
    timeout: 30.0,
);

// INTERACTIVE — forward stdin. If STDIN is a TTY it is used automatically.
$linops->executeCommand(
    'yum update',
    OutputMode::INTERACTIVE,
    function (string $stream, string $data) {
        fwrite($stream === 'stderr' ? STDERR : STDOUT, $data);
    },
    timeout: 3600.0,
);
```

### Fallback behaviour

If the server refuses the PTY shell channel during `STREAM` or `INTERACTIVE`,
LinOps does **not** silently retry via `exec()`. That would re-run the
command on a fresh connection (dangerous for non-idempotent commands such as
`yum update`) and would drop the interactive capability without warning. You
can opt in explicitly:

```php
$linops->executeCommand(
    'yum update -y',
    OutputMode::STREAM,
    $onChunk,
    allowFallback: true, // only safe for idempotent commands
);
```

Even when fallback is enabled, a `WorkerException` is thrown if the PTY write
fails. If the connection dies *after* the command has started, LinOps does not
retry — the exit code is `-1` and a message is streamed to `stderr`.

## Interactive shell

```php
$linops->openShell(
    onChunk: function (string $stream, string $data) {
        fwrite($stream === 'stderr' ? STDERR : STDOUT, $data);
    },
    timeout: 3600.0,
);
```

`openShell()` opens a PTY shell with no command, giving you the equivalent of
`ssh user@host`. There is no exec fallback: if the server refuses the shell
channel, a `WorkerException` is thrown. For elevation, type `sudo -i` inside
the shell.

For raw-mode keystroke forwarding (Ctrl+C, Tab, arrow keys) see the
accompanying `run-yum.php` and `run-yum-shell.php` scripts — they switch the
local terminal into raw mode before calling `executeCommand(INTERACTIVE)` /
`openShell()`.

## Security notes

- **Host key verified before credentials.** `SshConnector` calls
  `getServerPublicHostKey()` and runs the host key through
  `HostKeyVerifier` *before* `login()`, so a spoofed server cannot capture your
  password or key material.
- **Secrets are redacted.** `ServerConfig::__debugInfo()` returns `***` for
  `password`, `privateKey`, and `privateKeyPassphrase`.
  `getConnectionKey()` is a SHA-256 of host/port/username/mode only.
- **No sudo password handling.** `sudo -n` is used exclusively. If your sudoers
  policy requires a password, use `openShell()` and type it interactively.
- **SFTP is subject to the same host-key policy.** The dedicated SFTP
  connection goes through the same `SshConnector` and verification path.
- **Fallback is opt-in.** Silent `exec()` fallback is disabled by default and
  must be requested via `allowFallback: true`.

## Architecture

```
┌──────────────┐    ┌─────────────────────┐    ┌──────────────────────┐
│  Application │───▶│  LinOps / ServerPool│───▶│ CommandExecutor      │
└──────────────┘    └─────────────────────┘    └──────────────────────┘
                                                         │
                          ┌──────────────────────────────┴──────────────┐
                          ▼                                             ▼
                 ┌─────────────────────┐                    ┌───────────────────┐
                 │ WorkerPoolTransport │                    │ LocalCommandRunner│
                 └─────────────────────┘                    └───────────────────┘
                          │
                          ▼
                 ┌─────────────────────┐      JSON over stdio
                 │  worker.php         │◀────────────────────┐
                 │  (blocking phpseclib)│                     │
                 └─────────────────────┘                     │
                          ▲                                   │
                 ┌────────┴─────────┐                         │
                 │ streaming-worker │─────────────────────────┘
                 │  (PTY + exec)    │
                 └──────────────────┘
```

- Blocking phpseclib work is confined to isolated worker processes, one per
  connection key.
- `WorkerPoolTransport` uses a global `LocalSemaphore` for total concurrency
  and a `LocalMutex` per server so two requests never interleave on the same
  worker's stdin/stdout.
- Workers are evicted with LRU semantics; a worker currently serving a
  request is never evicted.
- On cancellation, `WorkerHandle::readLine()` invalidates the worker and kills
  the process so a subsequent request cannot read a stale response.


## License

MIT. See [LICENSE](LICENSE).