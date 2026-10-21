<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Tests\Unit;

use AfazTech\LinOps\System\HardwareInspector;
use PHPUnit\Framework\TestCase;

final class HardwareInspectorTest extends TestCase
{
    public function testEmptyInputProducesEmptyHardwareInfo(): void
    {
        $info = HardwareInspector::parse('');

        self::assertNull($info->cpu);
        self::assertNull($info->memory);
        self::assertSame([], $info->disks);
        self::assertNull($info->motherboard);
        self::assertNull($info->bios);
        self::assertArrayHasKey('cpu', $info->unavailable);
    }

    public function testParsesCpuFromProcWhenLscpuMissing(): void
    {
        $raw = <<<'RAW'
===SRC:cpuinfo===
processor	: 0
vendor_id	: GenuineIntel
cpu family	: 6
model name	: Intel(R) Xeon(R) CPU E5-2620 v4 @ 2.10GHz
cpu cores	: 8
physical id	: 0
flags		: fpu vme de pse msr

processor	: 1
vendor_id	: GenuineIntel
model name	: Intel(R) Xeon(R) CPU E5-2620 v4 @ 2.10GHz
cpu cores	: 8
physical id	: 0
flags		: fpu vme de pse msr

===SRC:lscpu===
===SRC:lsblk===
===SRC:dmi===
===SRC:smart===
===SRC:nvme===
===SRC:meta===
lscpu_available:no
lsblk_available:no
dmidecode_available:no
smartctl_available:no
nvme_available:no
is_root:no
===END===
RAW;

        $info = HardwareInspector::parse($raw);

        self::assertNotNull($info->cpu);
        self::assertSame('GenuineIntel', $info->cpu->vendor);
        self::assertStringContainsString('E5-2620', (string) $info->cpu->model);
        self::assertSame(2, $info->cpu->logicalThreads);
        self::assertSame(1, $info->cpu->sockets);
        self::assertSame(8, $info->cpu->physicalCores);
        self::assertContains('/proc/cpuinfo', $info->cpu->sources);
    }

    public function testParsesLscpuJson(): void
    {
        $lscpuJson = json_encode([
            'lscpu' => [
                ['field' => 'Architecture:', 'data' => 'x86_64'],
                ['field' => 'CPU(s):', 'data' => '16'],
                ['field' => 'Socket(s):', 'data' => '2'],
                ['field' => 'Core(s) per socket:', 'data' => '4'],
                ['field' => 'Model name:', 'data' => 'AMD EPYC 7302'],
                ['field' => 'Vendor ID:', 'data' => 'AuthenticAMD'],
                ['field' => 'CPU max MHz:', 'data' => '3000.0000'],
                ['field' => 'CPU min MHz:', 'data' => '1500.0000'],
                ['field' => 'Flags:', 'data' => 'fpu vme de pse'],
            ],
        ]);

        $raw = "===SRC:cpuinfo===\n"
            . "===SRC:lscpu===\n{$lscpuJson}\n"
            . "===SRC:lsblk===\n"
            . "===SRC:dmi===\n"
            . "===SRC:smart===\n"
            . "===SRC:nvme===\n"
            . "===SRC:meta===\n"
            . "lscpu_available:yes\n"
            . "lsblk_available:no\n"
            . "dmidecode_available:no\n"
            . "smartctl_available:no\n"
            . "nvme_available:no\n"
            . "is_root:no\n"
            . "===END===\n";

        $info = HardwareInspector::parse($raw);

        self::assertNotNull($info->cpu);
        self::assertSame('AMD EPYC 7302', $info->cpu->model);
        self::assertSame('AuthenticAMD', $info->cpu->vendor);
        self::assertSame('x86_64', $info->cpu->architecture);
        self::assertSame(16, $info->cpu->logicalThreads);
        self::assertSame(2, $info->cpu->sockets);
        self::assertSame(8, $info->cpu->physicalCores);
        self::assertSame(3000.0, $info->cpu->maxMhz);
        self::assertSame(1500.0, $info->cpu->minMhz);
    }

    public function testParsesLsblkJson(): void
    {
        $lsblkJson = json_encode([
            'blockdevices' => [
                [
                    'name' => 'sda',
                    'size' => 500107862016,
                    'type' => 'disk',
                    'model' => 'Samsung SSD 860',
                    'serial' => 'S3Z1NB0M123',
                    'tran' => 'sata',
                    'rota' => '0',
                ],
                [
                    'name' => 'sda1',
                    'size' => 524288000,
                    'type' => 'part',
                ],
            ],
        ]);

        $raw = "===SRC:cpuinfo===\n"
            . "===SRC:lscpu===\n"
            . "===SRC:lsblk===\n{$lsblkJson}\n"
            . "===SRC:dmi===\n"
            . "===SRC:smart===\n---smart:/dev/sda---\n"
            . "Device Model:     Samsung SSD 860\n"
            . "Serial Number:    S3Z1NB0M123\n"
            . "SMART overall-health self-assessment test result: PASSED\n"
            . "===SRC:nvme===\n"
            . "===SRC:meta===\n"
            . "lscpu_available:no\n"
            . "lsblk_available:yes\n"
            . "dmidecode_available:no\n"
            . "smartctl_available:yes\n"
            . "nvme_available:no\n"
            . "is_root:yes\n"
            . "===END===\n";

        $info = HardwareInspector::parse($raw);

        self::assertCount(1, $info->disks);
        $disk = $info->disks[0];
        self::assertSame('sda', $disk->name);
        self::assertSame(500107862016, $disk->sizeBytes);
        self::assertSame('Samsung SSD 860', $disk->model);
        self::assertSame('sata', $disk->transport);
        self::assertFalse($disk->rotational);
        self::assertSame('PASSED', $disk->health);
    }

    public function testParsesDmidecodeMemory(): void
    {
        $raw = "===SRC:cpuinfo===\n"
            . "===SRC:lscpu===\n"
            . "===SRC:lsblk===\n"
            . "===SRC:dmi===\n"
            . "---dmi:0---\n"
            . "Handle 0x0000, DMI type 0, 24 bytes\n"
            . "BIOS Information\n"
            . "\tVendor: American Megatrends Inc.\n"
            . "\tVersion: 2.10\n"
            . "\tRelease Date: 06/15/2021\n"
            . "---dmi:2---\n"
            . "Handle 0x0001, DMI type 2, 15 bytes\n"
            . "Base Board Information\n"
            . "\tManufacturer: ASUS\n"
            . "\tProduct Name: P11C-X\n"
            . "\tVersion: Rev 1.0\n"
            . "\tSerial Number: 123456789\n"
            . "---dmi:17---\n"
            . "Handle 0x0025, DMI type 17, 92 bytes\n"
            . "Memory Device\n"
            . "\tSize: 16384 MB\n"
            . "\tLocator: DIMM_A1\n"
            . "\tType: DDR4\n"
            . "\tSpeed: 2400 MT/s\n"
            . "\tManufacturer: Samsung\n"
            . "\tPart Number: M393A2K43BB1-CTD\n"
            . "\tSerial Number: 12345ABC\n"
            . "Handle 0x0026, DMI type 17, 92 bytes\n"
            . "Memory Device\n"
            . "\tSize: 16384 MB\n"
            . "\tLocator: DIMM_B1\n"
            . "\tType: DDR4\n"
            . "\tSpeed: 2400 MT/s\n"
            . "\tManufacturer: Samsung\n"
            . "\tPart Number: M393A2K43BB1-CTD\n"
            . "\tSerial Number: 67890DEF\n"
            . "===SRC:smart===\n"
            . "===SRC:nvme===\n"
            . "===SRC:meta===\n"
            . "lscpu_available:no\n"
            . "lsblk_available:no\n"
            . "dmidecode_available:yes\n"
            . "smartctl_available:no\n"
            . "nvme_available:no\n"
            . "is_root:yes\n"
            . "===END===\n";

        $info = HardwareInspector::parse($raw);

        self::assertNotNull($info->memory);
        self::assertSame(2, $info->memory->moduleCount);
        self::assertSame(2 * 16384 * 1024 ** 2, $info->memory->totalInstalledBytes);
        self::assertCount(2, $info->memory->modules);
        self::assertSame('DIMM_A1', $info->memory->modules[0]->locator);
        self::assertSame('DDR4', $info->memory->modules[0]->type);
        self::assertSame(2400, $info->memory->modules[0]->speedMts);

        self::assertNotNull($info->motherboard);
        self::assertSame('ASUS', $info->motherboard->manufacturer);
        self::assertSame('P11C-X', $info->motherboard->productName);

        self::assertNotNull($info->bios);
        self::assertSame('American Megatrends Inc.', $info->bios->vendor);
        self::assertSame('2.10', $info->bios->version);
        self::assertSame('06/15/2021', $info->bios->releaseDate);
    }

    public function testDmiUnavailableWhenNotRoot(): void
    {
        $raw = "===SRC:cpuinfo===\n"
            . "===SRC:lscpu===\n"
            . "===SRC:lsblk===\n"
            . "===SRC:dmi===\n"
            . "===SRC:smart===\n"
            . "===SRC:nvme===\n"
            . "===SRC:meta===\n"
            . "lscpu_available:no\n"
            . "lsblk_available:no\n"
            . "dmidecode_available:yes\n"
            . "smartctl_available:no\n"
            . "nvme_available:no\n"
            . "is_root:no\n"
            . "===END===\n";

        $info = HardwareInspector::parse($raw);

        self::assertNull($info->memory);
        self::assertArrayHasKey('memory', $info->unavailable);
        self::assertStringContainsString('root', $info->unavailable['memory']);
    }
}
