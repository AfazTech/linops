<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\System\VirtualizationInspector;
use AfazTech\LinOps\Support\ServerType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VirtualizationInspectorTest extends TestCase
{
    private VirtualizationInspector $inspector;

    protected function setUp(): void
    {
        // evaluate() is a pure function; no executor is needed.
        $ref = new ReflectionClass(VirtualizationInspector::class);
        $this->inspector = $ref->newInstanceWithoutConstructor();
    }

    public function testKvmVpsIsDetected(): void
    {
        $raw = [
            'virt' => 'kvm',
            'container' => 'none',
            'sys_vendor' => 'QEMU',
            'product_name' => 'Standard PC (i440FX + PIIX, 1996)',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => 'Other',
            'cpu_hypervisor_flag' => 'hypervisor',
            'lscpu_hypervisor' => 'KVM',
            'lscpu_virt_type' => 'full',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::VPS, $info->type);
        self::assertTrue($info->isVps());
        self::assertSame(1.0, $info->confidence);
        self::assertSame('kvm', $info->hypervisor);
        self::assertNotEmpty($info->evidence);
    }

    public function testBareMetalDedicatedIsDetected(): void
    {
        $raw = [
            'virt' => 'none',
            'container' => 'none',
            'sys_vendor' => '',
            'product_name' => '',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => '',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::DEDICATED, $info->type);
        self::assertTrue($info->isDedicated());
        self::assertNull($info->hypervisor);
        self::assertGreaterThanOrEqual(0.5, $info->confidence);
    }

    public function testDellServerIsDedicated(): void
    {
        $raw = [
            'virt' => 'none',
            'container' => 'none',
            'sys_vendor' => 'Dell Inc.',
            'product_name' => 'PowerEdge R740',
            'board_vendor' => 'Dell Inc.',
            'board_name' => '0XXXXX',
            'chassis_type' => 'Rack Mount Chassis',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::DEDICATED, $info->type);
    }

    /**
     * Regression: a `systemd-detect-virt --vm || systemd-detect-virt`
     * pipeline on bare metal produces "none\nnone". The former evaluate()
     * treated that multi-line string as a VM signature and reported a
     * physical desktop as VPS. The fix normalizes each field to its first
     * non-empty line, so this must now classify as DEDICATED.
     */
    public function testMultiLineVirtOutputIsTreatedAsDedicated(): void
    {
        $raw = [
            'virt' => "none\nnone",
            'container' => "none\nnone",
            'sys_vendor' => 'Hewlett-Packard',
            'product_name' => 'HP EliteDesk 800 G1 SFF',
            'board_vendor' => 'Hewlett-Packard',
            'board_name' => '1998',
            'chassis_type' => '4',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::DEDICATED, $info->type);
        self::assertNull($info->hypervisor);
    }

    public function testPhysicalHpWorkstationIsDedicated(): void
    {
        $raw = [
            'virt' => 'none',
            'container' => 'none',
            'sys_vendor' => 'Hewlett-Packard',
            'product_name' => 'HP EliteDesk 800 G1 SFF',
            'board_vendor' => 'Hewlett-Packard',
            'board_name' => '1998',
            'chassis_type' => '4',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::DEDICATED, $info->type);
        self::assertNull($info->hypervisor);
    }

    public function testVirtUnavailableButLscpuShowsHypervisor(): void
    {
        $raw = [
            'virt' => '',
            'container' => '',
            'sys_vendor' => '',
            'product_name' => '',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => '',
            'cpu_hypervisor_flag' => 'hypervisor',
            'lscpu_hypervisor' => 'KVM',
            'lscpu_virt_type' => 'full',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::VPS, $info->type);
        self::assertSame('kvm', $info->hypervisor);
    }

    public function testDmiOnlyQemuIsVps(): void
    {
        $raw = [
            'virt' => 'none',
            'container' => 'none',
            'sys_vendor' => 'QEMU',
            'product_name' => '',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => '',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::VPS, $info->type);
        self::assertSame('qemu', $info->hypervisor);
        self::assertSame(0.9, $info->confidence);
    }

    public function testChassisOtherOnlyIsWeakVps(): void
    {
        $raw = [
            'virt' => '',
            'container' => '',
            'sys_vendor' => '',
            'product_name' => '',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => 'Other',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::VPS, $info->type);
        self::assertLessThan(0.5, $info->confidence);
    }

    public function testUnknownWhenNoSignalsAndVirtUnavailable(): void
    {
        $raw = [
            'virt' => '',
            'container' => '',
            'sys_vendor' => '',
            'product_name' => '',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => '',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::UNKNOWN, $info->type);
        self::assertTrue($info->isUnknown());
    }

    public function testVmwareVendorMatch(): void
    {
        $raw = [
            'virt' => '',
            'container' => '',
            'sys_vendor' => 'VMware, Inc.',
            'product_name' => 'VMware Virtual Platform',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => '',
            'cpu_hypervisor_flag' => 'hypervisor',
            'lscpu_hypervisor' => 'VMware',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::VPS, $info->type);
        self::assertSame('vmware', $info->hypervisor);
    }

    public function testContainerHintIsRecordedButDoesNotChangeType(): void
    {
        $raw = [
            'virt' => 'none',
            'container' => 'docker',
            'sys_vendor' => '',
            'product_name' => '',
            'board_vendor' => '',
            'board_name' => '',
            'chassis_type' => '',
            'cpu_hypervisor_flag' => '',
            'lscpu_hypervisor' => '',
            'lscpu_virt_type' => '',
        ];

        $info = $this->inspector->evaluate($raw);

        self::assertSame(ServerType::DEDICATED, $info->type);
        self::assertNotEmpty(array_filter(
            $info->evidence,
            static fn (string $e): bool => str_contains($e, 'docker'),
        ));
    }
}
