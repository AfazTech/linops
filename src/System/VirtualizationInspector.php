<?php

declare(strict_types=1);

namespace AfazTech\LinOps\System;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\ServerTypeInfo;
use AfazTech\LinOps\ServerConfig;
use AfazTech\LinOps\Support\ServerType;

final class VirtualizationInspector
{
    /**
     * Substrings matched (case-insensitively) against sys_vendor,
     * board_vendor, board_name, and product_name. The map value is the
     * canonical hypervisor name reported in ServerTypeInfo::$hypervisor.
     */
    private const VENDOR_SIGNATURES = [
        'qemu' => 'qemu',
        'kvm' => 'kvm',
        'vmware' => 'vmware',
        'innotek' => 'virtualbox',
        'virtualbox' => 'virtualbox',
        'xen' => 'xen',
        'bochs' => 'bochs',
        'parallels' => 'parallels',
        'microsoft corporation' => 'hyper-v',
        'microsoft hv' => 'hyper-v',
        'amazon ec2' => 'aws',
        'google compute engine' => 'gce',
        'google cloud' => 'gce',
        'digitalocean' => 'digitalocean',
        'droplet' => 'digitalocean',
        'openstack' => 'openstack',
        'alibaba cloud' => 'alibaba',
        'hetzner' => 'hetzner',
        'linode' => 'linode',
        'vultr' => 'vultr',
        'upcloud' => 'upcloud',
        'scaleway' => 'scaleway',
        'cloudsigma' => 'cloudsigma',
    ];

    private const PRODUCT_SIGNATURES = [
        'virtualbox' => 'virtualbox',
        'vmware virtual platform' => 'vmware',
        'vmware virtual machine' => 'vmware',
        'virtual machine' => 'generic-vm',
        'standard pc' => 'qemu',
        'kvm' => 'kvm',
        'xen hvm' => 'xen',
        'hvm domu' => 'xen',
        'amazon ec2' => 'aws',
        'google compute engine' => 'gce',
        'alibaba cloud ecs' => 'alibaba',
        'openstack nova' => 'openstack',
        'droplet' => 'digitalocean',
        'bochs' => 'bochs',
        'parallels virtual platform' => 'parallels',
        'rhev hypervisor' => 'rhev',
    ];

    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    public function detect(ServerConfig $config): ServerTypeInfo
    {
        return $this->evaluate($this->gather($config));
    }

    /**
     * Runs all probes in a single SSH call and returns the trimmed outputs
     * keyed by probe name. Multi-line output is reduced to the first
     * non-empty line, which is the only form these probes produce.
     *
     * @return array<string, string>
     */
    public function gather(ServerConfig $config): array
    {
        $script = <<<'SH'
set +e
echo "===virt==="
systemd-detect-virt --vm 2>/dev/null || true
echo "===container==="
systemd-detect-virt --container 2>/dev/null || true
echo "===sys_vendor==="
cat /sys/class/dmi/id/sys_vendor 2>/dev/null || true
echo "===product_name==="
cat /sys/class/dmi/id/product_name 2>/dev/null || true
echo "===board_vendor==="
cat /sys/class/dmi/id/board_vendor 2>/dev/null || true
echo "===board_name==="
cat /sys/class/dmi/id/board_name 2>/dev/null || true
echo "===chassis_type==="
cat /sys/class/dmi/id/chassis_type 2>/dev/null || dmidecode -s chassis-type 2>/dev/null || true
echo "===cpu_hypervisor_flag==="
grep -m1 -o hypervisor /proc/cpuinfo 2>/dev/null || true
echo "===lscpu_hypervisor==="
lscpu 2>/dev/null | grep -i '^Hypervisor vendor:' | sed 's/.*: *//' || true
echo "===lscpu_virt_type==="
lscpu 2>/dev/null | grep -i '^Virtualization type:' | sed 's/.*: *//' || true
echo "===end==="
SH;

        $result = $this->executor->executeSingle($config, $script, 15.0);

        $sections = [];
        $current = null;

        foreach (explode("\n", (string) $result->stdout) as $line) {
            if (preg_match('/^===(\w+)===$/', $line, $m) === 1) {
                $current = $m[1];
                $sections[$current] = '';
                continue;
            }
            if ($line === '===end===') {
                $current = null;
                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        $out = [];
        foreach ($sections as $key => $value) {
            $out[$key] = $this->firstLine($value);
        }

        return $out;
    }

    /**
     * Evaluate a raw probe map produced by gather(). Pure function so it can
     * be unit-tested without a real server. Each field is normalized to its
     * first non-empty line; this guards against probes that emit extra
     * lines (for example a `systemd-detect-virt --vm || systemd-detect-virt`
     * pipeline that would produce "none\nnone" on bare metal).
     *
     * @param array<string, string> $raw
     */
    public function evaluate(array $raw): ServerTypeInfo
    {
        $fields = [];
        foreach ($raw as $key => $value) {
            $fields[$key] = $this->firstLine((string) $value);
        }

        $evidence = [];
        $hypervisor = null;
        $bestConfidence = 0.0;

        // 1. systemd-detect-virt --vm (strongest single signal).
        $virt = strtolower($fields['virt'] ?? '');
        $virtExplicitNone = ($virt === 'none');
        if ($virt !== '' && $virt !== 'none' && $virt !== 'unknown') {
            $evidence[] = "systemd-detect-virt: {$virt}";
            $bestConfidence = max($bestConfidence, 1.0);
            $hypervisor = $this->normalizeHypervisor($virt);
        }

        // 2. lscpu "Hypervisor vendor".
        $lscpuHyper = $fields['lscpu_hypervisor'] ?? '';
        if ($lscpuHyper !== '') {
            $evidence[] = "lscpu: Hypervisor vendor = {$lscpuHyper}";
            $bestConfidence = max($bestConfidence, 0.95);
            $hypervisor = $hypervisor ?? $this->normalizeHypervisor($lscpuHyper);
        }

        // 3. /proc/cpuinfo "hypervisor" flag.
        if (strtolower($fields['cpu_hypervisor_flag'] ?? '') === 'hypervisor') {
            $evidence[] = 'cpuinfo: hypervisor flag present';
            $bestConfidence = max($bestConfidence, 0.9);
        }

        // 4. DMI vendor and product names.
        $dmiFields = [
            'sys_vendor' => 0.9,
            'board_vendor' => 0.85,
            'board_name' => 0.85,
            'product_name' => 0.85,
        ];

        foreach ($dmiFields as $field => $weight) {
            $value = $fields[$field] ?? '';
            if ($value === '') {
                continue;
            }
            $lower = strtolower($value);
            $match = $this->matchSignature($lower, self::VENDOR_SIGNATURES)
                ?? $this->matchSignature($lower, self::PRODUCT_SIGNATURES);
            if ($match !== null) {
                $evidence[] = "{$field}: '{$value}' matches '{$match}'";
                $bestConfidence = max($bestConfidence, $weight);
                $hypervisor = $hypervisor ?? $match;
            }
        }

        // 5. Chassis type Other (1) / Unknown (2) as a weak VM signal.
        $chassis = strtolower($fields['chassis_type'] ?? '');
        if (in_array($chassis, ['other', '1', 'unknown', '2'], true)) {
            $evidence[] = "chassis_type: '{$fields['chassis_type']}' (weak signal)";
            $bestConfidence = max($bestConfidence, 0.25);
        }

        // Container hint for context. Does not affect VPS/dedicated decision.
        $container = strtolower($fields['container'] ?? '');
        if ($container !== '' && $container !== 'none' && $container !== 'unknown') {
            $evidence[] = "systemd-detect-virt --container: {$container}";
        }

        if ($bestConfidence > 0.0) {
            return new ServerTypeInfo(
                type: ServerType::VPS,
                confidence: round($bestConfidence, 2),
                hypervisor: $hypervisor,
                evidence: $evidence,
                raw: $fields,
            );
        }

        if ($virtExplicitNone) {
            $evidence[] = 'systemd-detect-virt: none';
            $evidence[] = 'no virtualization signatures detected';

            return new ServerTypeInfo(
                type: ServerType::DEDICATED,
                confidence: 0.8,
                hypervisor: null,
                evidence: $evidence,
                raw: $fields,
            );
        }

        return new ServerTypeInfo(
            type: ServerType::UNKNOWN,
            confidence: 0.0,
            hypervisor: null,
            evidence: $evidence,
            raw: $fields,
        );
    }

    private function firstLine(string $value): string
    {
        foreach (explode("\n", $value) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    /** @param array<string, string> $map */
    private function matchSignature(string $haystack, array $map): ?string
    {
        foreach ($map as $needle => $canonical) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $canonical;
            }
        }

        return null;
    }

    private function normalizeHypervisor(string $value): string
    {
        $lower = strtolower(trim($value));

        return match (true) {
            str_contains($lower, 'kvm') => 'kvm',
            str_contains($lower, 'vmware') => 'vmware',
            str_contains($lower, 'xen') => 'xen',
            str_contains($lower, 'microsoft') => 'hyper-v',
            str_contains($lower, 'hyper-v') => 'hyper-v',
            str_contains($lower, 'qemu') => 'qemu',
            str_contains($lower, 'parallels') => 'parallels',
            str_contains($lower, 'oracle') => 'oracle-vm',
            str_contains($lower, 'bochs') => 'bochs',
            default => $lower,
        };
    }
}
