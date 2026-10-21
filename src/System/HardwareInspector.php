<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\BiosHardwareInfo;
use AfazTech\LinOps\Result\CpuHardwareInfo;
use AfazTech\LinOps\Result\DiskHardwareInfo;
use AfazTech\LinOps\Result\HardwareInfo;
use AfazTech\LinOps\Result\MemoryHardwareInfo;
use AfazTech\LinOps\Result\MemoryModuleInfo;
use AfazTech\LinOps\Result\MotherboardHardwareInfo;
use AfazTech\LinOps\ServerConfig;

final class HardwareInspector
{
    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    public function inspect(ServerConfig $config): HardwareInfo
    {
        return self::parse($this->gather($config));
    }

    private function gather(ServerConfig $config): string
    {
        $script = <<<'SH'
echo "===SRC:cpuinfo==="
cat /proc/cpuinfo 2>/dev/null || true
echo "===SRC:lscpu==="
if command -v lscpu >/dev/null 2>&1; then lscpu -J 2>/dev/null || true; fi
echo "===SRC:lsblk==="
if command -v lsblk >/dev/null 2>&1; then lsblk -J -b -o NAME,SIZE,TYPE,MODEL,SERIAL,TRAN,ROTA 2>/dev/null || true; fi
echo "===SRC:dmi==="
if command -v dmidecode >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    echo "---dmi:0---"
    dmidecode -t 0 2>/dev/null || true
    echo "---dmi:2---"
    dmidecode -t 2 2>/dev/null || true
    echo "---dmi:17---"
    dmidecode -t 17 2>/dev/null || true
fi
echo "===SRC:sysfs==="
for f in board_vendor board_name board_version board_serial bios_vendor bios_version bios_date; do
    v=""
    [ -r "/sys/class/dmi/id/$f" ] && v=$(cat "/sys/class/dmi/id/$f" 2>/dev/null)
    echo "$f:$v"
done
echo "===SRC:smart==="
if command -v smartctl >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    for dev in $(smartctl --scan 2>/dev/null | awk '{print $1}'); do
        echo "---smart:$dev---"
        smartctl -i -H "$dev" 2>/dev/null | head -40
    done
fi
echo "===SRC:nvme==="
if command -v nvme >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    for dev in $(lsblk -dn -o NAME 2>/dev/null | grep '^nvme'); do
        echo "---nvme:/dev/$dev---"
        nvme smart-log "/dev/$dev" 2>/dev/null | head -30
    done
fi
echo "===SRC:meta==="
echo "dmidecode_available:$(command -v dmidecode >/dev/null 2>&1 && echo yes || echo no)"
echo "smartctl_available:$(command -v smartctl >/dev/null 2>&1 && echo yes || echo no)"
echo "nvme_available:$(command -v nvme >/dev/null 2>&1 && echo yes || echo no)"
echo "lscpu_available:$(command -v lscpu >/dev/null 2>&1 && echo yes || echo no)"
echo "lsblk_available:$(command -v lsblk >/dev/null 2>&1 && echo yes || echo no)"
echo "is_root:$([ "$(id -u)" = "0" ] && echo yes || echo no)"
echo "===END==="
SH;

        $result = $this->executor->executeSingle($config, $script, 45.0);

        return (string) $result->stdout;
    }

    public static function parse(string $raw): HardwareInfo
    {
        $sections = self::splitSections($raw);
        $unavailable = [];

        $meta = self::parseMeta($sections['meta'] ?? '');
        $sources = [
            'lscpu' => ($meta['lscpu_available'] ?? false) === true,
            'lsblk' => ($meta['lsblk_available'] ?? false) === true,
            'dmidecode' => ($meta['dmidecode_available'] ?? false) === true,
            'smartctl' => ($meta['smartctl_available'] ?? false) === true,
            'nvme' => ($meta['nvme_available'] ?? false) === true,
            'root' => ($meta['is_root'] ?? false) === true,
        ];

        return new HardwareInfo(
            cpu: self::parseCpu($sections, $unavailable),
            memory: self::parseMemory($sections, $unavailable, $sources),
            disks: self::parseDisks($sections, $unavailable, $sources),
            motherboard: self::parseMotherboard($sections, $unavailable, $sources),
            bios: self::parseBios($sections, $unavailable, $sources),
            sources: $sources,
            unavailable: $unavailable,
        );
    }

    /** @return array<string, string> */
    private static function splitSections(string $raw): array
    {
        $sections = [];
        $current = null;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^===SRC:(\w+)===$/', $line, $m) === 1) {
                $current = $m[1];
                $sections[$current] = '';
            } elseif ($line === '===END===') {
                $current = null;
            } elseif ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
    }

    /** @return array<string, bool> */
    private static function parseMeta(string $raw): array
    {
        $result = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if (preg_match('/^(\w+):(\w+)$/', trim($line), $m) === 1) {
                $result[$m[1]] = $m[2] === 'yes';
            }
        }
        return $result;
    }

    /** @param array<string, string> $sections */
    private static function parseCpu(array $sections, array &$unavailable): ?CpuHardwareInfo
    {
        $fromLscpu = null;
        if (($sections['lscpu'] ?? '') !== '') {
            $decoded = json_decode($sections['lscpu'], true);
            if (is_array($decoded) && isset($decoded['lscpu']) && is_array($decoded['lscpu'])) {
                $fromLscpu = self::parseLscpu($decoded['lscpu']);
            }
        }

        $fromProc = self::parseProcCpu($sections['cpuinfo'] ?? '');

        if ($fromLscpu === null && $fromProc === null) {
            $unavailable['cpu'] = 'Neither lscpu nor /proc/cpuinfo produced usable output.';
            return null;
        }

        return new CpuHardwareInfo(
            model: $fromLscpu['model'] ?? $fromProc['model'] ?? null,
            vendor: $fromLscpu['vendor'] ?? $fromProc['vendor'] ?? null,
            architecture: $fromLscpu['architecture'] ?? null,
            physicalCores: $fromLscpu['physical_cores'] ?? $fromProc['physical_cores'] ?? null,
            logicalThreads: $fromLscpu['logical_threads'] ?? $fromProc['logical_threads'] ?? null,
            sockets: $fromLscpu['sockets'] ?? $fromProc['sockets'] ?? null,
            maxMhz: $fromLscpu['max_mhz'] ?? null,
            minMhz: $fromLscpu['min_mhz'] ?? null,
            flags: $fromLscpu['flags'] ?? $fromProc['flags'] ?? [],
            sources: array_values(array_filter([
                $fromLscpu !== null ? 'lscpu' : null,
                $fromProc !== null ? '/proc/cpuinfo' : null,
            ])),
        );
    }

    /** @param list<array{field?: string, data?: string}> $data */
    private static function parseLscpu(array $data): array
    {
        $map = [];
        foreach ($data as $entry) {
            $field = rtrim((string) ($entry['field'] ?? ''), " \t:");
            if ($field === '') {
                continue;
            }
            $map[$field] = (string) ($entry['data'] ?? '');
        }

        $sockets = isset($map['Socket(s)']) ? (int) $map['Socket(s)'] : null;
        $coresPerSocket = isset($map['Core(s) per socket']) ? (int) $map['Core(s) per socket'] : null;

        return [
            'model' => isset($map['Model name']) && trim($map['Model name']) !== '' ? trim($map['Model name']) : null,
            'vendor' => isset($map['Vendor ID']) && trim($map['Vendor ID']) !== '' ? trim($map['Vendor ID']) : null,
            'architecture' => isset($map['Architecture']) && trim($map['Architecture']) !== '' ? trim($map['Architecture']) : null,
            'physical_cores' => $sockets !== null && $coresPerSocket !== null ? $sockets * $coresPerSocket : null,
            'logical_threads' => isset($map['CPU(s)']) ? (int) $map['CPU(s)'] : null,
            'sockets' => $sockets,
            'max_mhz' => isset($map['CPU max MHz']) && is_numeric($map['CPU max MHz']) ? (float) $map['CPU max MHz'] : null,
            'min_mhz' => isset($map['CPU min MHz']) && is_numeric($map['CPU min MHz']) ? (float) $map['CPU min MHz'] : null,
            'flags' => isset($map['Flags']) ? array_values(array_filter(explode(' ', (string) $map['Flags']))) : [],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parseProcCpu(string $raw): ?array
    {
        $info = [];
        $physicalIds = [];
        $cpuCoresValues = [];
        $processorCount = 0;
        $currentHasData = false;

        foreach (explode("\n", $raw) as $line) {
            if (trim($line) === '') {
                $currentHasData = false;
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === 'processor') {
                $processorCount++;
                $currentHasData = true;
                continue;
            }
            if (!$currentHasData) {
                continue;
            }
            if (!isset($info[$key]) && $value !== '') {
                $info[$key] = $value;
            }
            if ($key === 'physical id') {
                $physicalIds[] = $value;
            } elseif ($key === 'cpu cores') {
                $cpuCoresValues[] = (int) $value;
            }
        }

        if ($info === [] && $processorCount === 0) {
            return null;
        }

        $sockets = $physicalIds === [] ? null : count(array_unique($physicalIds));
        $coresPerSocket = $cpuCoresValues === [] ? null : max($cpuCoresValues);

        return [
            'model' => $info['model name'] ?? null,
            'vendor' => $info['vendor_id'] ?? null,
            'physical_cores' => $sockets !== null && $coresPerSocket !== null ? $sockets * $coresPerSocket : null,
            'logical_threads' => $processorCount > 0 ? $processorCount : null,
            'sockets' => $sockets,
            'flags' => isset($info['flags']) ? array_values(array_filter(explode(' ', $info['flags']))) : [],
        ];
    }

    /** @param array<string, string> $sections */
    private static function parseMemory(array $sections, array &$unavailable, array $sources): ?MemoryHardwareInfo
    {
        $dmi = $sections['dmi'] ?? '';
        if ($dmi === '') {
            $unavailable['memory'] = self::reasonForDmi($sources, 'Memory module details');
            return null;
        }

        $blocks = self::extractDmiBlocks($dmi, '17');
        if ($blocks === []) {
            $unavailable['memory'] = 'dmidecode -t 17 produced no parsable blocks.';
            return null;
        }

        $modules = [];
        $totalBytes = 0;
        foreach ($blocks as $block) {
            $size = $block['Size'] ?? '';
            if (str_contains($size, 'No Module Installed') || str_contains($size, 'Not Installed')) {
                continue;
            }

            $sizeBytes = self::parseDmiSize($size);
            if ($sizeBytes === null) {
                continue;
            }

            $speedMts = null;
            if (isset($block['Speed']) && preg_match('/^(\d+)\s*MT\/s$/i', trim($block['Speed']), $m) === 1) {
                $speedMts = (int) $m[1];
            }

            $modules[] = new MemoryModuleInfo(
                locator: self::cleanDmiValue($block['Locator'] ?? null),
                sizeBytes: $sizeBytes,
                type: self::cleanDmiValue($block['Type'] ?? null),
                speedMts: $speedMts,
                manufacturer: self::cleanDmiValue($block['Manufacturer'] ?? null),
                partNumber: self::cleanDmiValue($block['Part Number'] ?? null),
                serial: self::cleanDmiValue($block['Serial Number'] ?? null),
            );
            $totalBytes += $sizeBytes;
        }

        if ($modules === []) {
            $unavailable['memory'] = 'dmidecode returned no populated memory slots.';
            return null;
        }

        return new MemoryHardwareInfo(
            totalInstalledBytes: $totalBytes,
            moduleCount: count($modules),
            modules: $modules,
            source: 'dmidecode -t 17',
        );
    }

    /** @return list<DiskHardwareInfo> */
    private static function parseDisks(array $sections, array &$unavailable, array $sources): array
    {
        $lsblkRaw = trim($sections['lsblk'] ?? '');
        if ($lsblkRaw === '') {
            $unavailable['disks'] = $sources['lsblk'] ? 'lsblk produced no output.' : 'lsblk is not installed.';
            return [];
        }

        $decoded = json_decode($lsblkRaw, true);
        if (!is_array($decoded) || !isset($decoded['blockdevices']) || !is_array($decoded['blockdevices'])) {
            $unavailable['disks'] = 'lsblk output could not be parsed as JSON.';
            return [];
        }

        $smart = self::parseSmart($sections['smart'] ?? '');
        $nvme = self::parseNvme($sections['nvme'] ?? '');

        $disks = [];
        foreach ($decoded['blockdevices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $name = (string) ($device['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $kind = (string) ($device['type'] ?? '');
            if ($kind === 'part') {
                continue;
            }

            $deviceKey = '/dev/' . $name;
            $smartInfo = $smart[$deviceKey] ?? [];
            $nvmeInfo = $nvme[$deviceKey] ?? [];

            $usedSources = ['lsblk'];
            if ($smartInfo !== []) {
                $usedSources[] = 'smartctl';
            }
            if ($nvmeInfo !== []) {
                $usedSources[] = 'nvme';
            }

            $model = isset($device['model']) ? trim((string) $device['model']) : '';
            $serial = isset($device['serial']) ? trim((string) $device['serial']) : '';

            $disks[] = new DiskHardwareInfo(
                name: $name,
                sizeBytes: isset($device['size']) ? (int) $device['size'] : null,
                type: $kind !== '' ? $kind : null,
                model: $model !== '' ? $model : ($smartInfo['model'] ?? null),
                serial: $serial !== '' ? $serial : ($smartInfo['serial'] ?? null),
                transport: isset($device['tran']) && (string) $device['tran'] !== '' ? (string) $device['tran'] : null,
                rotational: isset($device['rota']) ? (bool) filter_var($device['rota'], FILTER_VALIDATE_BOOLEAN) : null,
                health: $smartInfo['health'] ?? null,
                sources: $usedSources,
            );
        }

        if ($sources['smartctl'] === false) {
            $unavailable['disk_health'] = 'smartctl is not installed.';
        } elseif ($sources['root'] === false) {
            $unavailable['disk_health'] = 'smartctl requires root privileges.';
        }

        return $disks;
    }

    /** @param array<string, string> $sections */
    private static function parseMotherboard(array $sections, array &$unavailable, array $sources): ?MotherboardHardwareInfo
    {
        $dmi = $sections['dmi'] ?? '';
        if ($dmi !== '') {
            $blocks = self::extractDmiBlocks($dmi, '2');
            if ($blocks !== []) {
                $block = $blocks[0];
                return new MotherboardHardwareInfo(
                    manufacturer: self::cleanDmiValue($block['Manufacturer'] ?? null),
                    productName: self::cleanDmiValue($block['Product Name'] ?? null),
                    version: self::cleanDmiValue($block['Version'] ?? null),
                    serial: self::cleanDmiValue($block['Serial Number'] ?? null),
                );
            }
        }

        $sysfs = self::parseSysfs($sections['sysfs'] ?? '');
        if (!empty($sysfs['board_vendor']) || !empty($sysfs['board_name'])) {
            return new MotherboardHardwareInfo(
                manufacturer: self::cleanDmiValue($sysfs['board_vendor'] ?? null),
                productName: self::cleanDmiValue($sysfs['board_name'] ?? null),
                version: self::cleanDmiValue($sysfs['board_version'] ?? null),
                serial: self::cleanDmiValue($sysfs['board_serial'] ?? null),
            );
        }

        $unavailable['motherboard'] = self::reasonForDmi($sources, 'Motherboard');
        return null;
    }

    /** @param array<string, string> $sections */
    private static function parseBios(array $sections, array &$unavailable, array $sources): ?BiosHardwareInfo
    {
        $dmi = $sections['dmi'] ?? '';
        if ($dmi !== '') {
            $blocks = self::extractDmiBlocks($dmi, '0');
            if ($blocks !== []) {
                $block = $blocks[0];
                return new BiosHardwareInfo(
                    vendor: self::cleanDmiValue($block['Vendor'] ?? null),
                    version: self::cleanDmiValue($block['Version'] ?? null),
                    releaseDate: self::cleanDmiValue($block['Release Date'] ?? null),
                );
            }
        }

        $sysfs = self::parseSysfs($sections['sysfs'] ?? '');
        if (!empty($sysfs['bios_vendor']) || !empty($sysfs['bios_version'])) {
            return new BiosHardwareInfo(
                vendor: self::cleanDmiValue($sysfs['bios_vendor'] ?? null),
                version: self::cleanDmiValue($sysfs['bios_version'] ?? null),
                releaseDate: self::cleanDmiValue($sysfs['bios_date'] ?? null),
            );
        }

        $unavailable['bios'] = self::reasonForDmi($sources, 'BIOS');
        return null;
    }

    /** @return array<string, string> */
    private static function parseSysfs(string $raw): array
    {
        $result = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key !== '' && $value !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private static function cleanDmiValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $lower = strtolower($value);
        if (in_array($lower, ['not specified', 'not available', 'unknown', 'to be filled by o.e.m.', 'none', 'default string'], true)) {
            return null;
        }
        return $value;
    }

    /** @param array<string, bool> $sources */
    private static function reasonForDmi(array $sources, string $label): string
    {
        if ($sources['dmidecode'] === false) {
            return $label . ' requires dmidecode, which is not installed.';
        }
        if ($sources['root'] === false) {
            return $label . ' requires root privileges for dmidecode.';
        }
        return $label . ' could not be read from dmidecode or /sys/class/dmi/id.';
    }

    /** @return list<array<string, string>> */
    private static function extractDmiBlocks(string $raw, string $type): array
    {
        $typeSections = [];
        $activeType = null;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^---dmi:(\d+)---$/', trim($line), $m) === 1) {
                $activeType = $m[1];
                $typeSections[$activeType] = '';
                continue;
            }
            if ($activeType !== null) {
                $typeSections[$activeType] .= $line . "\n";
            }
        }

        $body = $typeSections[$type] ?? '';
        if (trim($body) === '') {
            return [];
        }

        $blocks = [];
        $buffer = [];

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'Handle ') && $buffer !== []) {
                $blocks[] = $buffer;
                $buffer = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key !== '' && $value !== '') {
                $buffer[$key] = $value;
            }
        }

        if ($buffer !== []) {
            $blocks[] = $buffer;
        }

        return $blocks;
    }

    private static function parseDmiSize(string $size): ?int
    {
        if (preg_match('/^(\d+)\s*(MB|GB|TB)$/i', trim($size), $m) !== 1) {
            return null;
        }
        $value = (int) $m[1];
        return match (strtoupper($m[2])) {
            'MB' => $value * 1024 ** 2,
            'GB' => $value * 1024 ** 3,
            'TB' => $value * 1024 ** 4,
            default => null,
        };
    }

    /** @return array<string, array{model?: ?string, serial?: ?string, health?: ?string}> */
    private static function parseSmart(string $raw): array
    {
        $result = [];
        $currentDevice = null;
        $current = [];

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^---smart:(.+?)---$/', trim($line), $m) === 1) {
                if ($currentDevice !== null && $current !== []) {
                    $result[$currentDevice] = $current;
                }
                $currentDevice = $m[1];
                $current = [];
                continue;
            }
            if ($currentDevice === null) {
                continue;
            }
            if (preg_match('/^Device Model:\s+(.+)$/', $line, $m) === 1) {
                $current['model'] = trim($m[1]);
            } elseif (preg_match('/^Serial Number:\s+(.+)$/', $line, $m) === 1) {
                $current['serial'] = trim($m[1]);
            } elseif (preg_match('/^SMART overall-health self-assessment test result:\s+(\w+)/', $line, $m) === 1) {
                $current['health'] = strtoupper($m[1]);
            }
        }

        if ($currentDevice !== null && $current !== []) {
            $result[$currentDevice] = $current;
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private static function parseNvme(string $raw): array
    {
        $result = [];
        $currentDevice = null;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^---nvme:(.+?)---$/', trim($line), $m) === 1) {
                $currentDevice = $m[1];
                $result[$currentDevice] = [];
                continue;
            }
            if ($currentDevice === null) {
                continue;
            }
            if (preg_match('/^temperature\s*:\s*(\d+)/', $line, $m) === 1) {
                $result[$currentDevice]['temperature_celsius'] = (int) $m[1];
            }
        }

        return $result;
    }
}
