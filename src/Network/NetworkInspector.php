<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Network;

use AfazTech\LinOps\Command\CommandExecutor;
use AfazTech\LinOps\Result\DnsConfigInfo;
use AfazTech\LinOps\Result\NetworkAddressInfo;
use AfazTech\LinOps\Result\RouteInfo;
use AfazTech\LinOps\ServerConfig;

final class NetworkInspector
{
    public function __construct(
        private readonly CommandExecutor $executor,
    ) {
    }

    /**
     * @return list<NetworkAddressInfo>
     */
    public function getAddresses(ServerConfig $config): array
    {
        $command = <<<'SH'
if command -v ip >/dev/null 2>&1; then
  ip -j addr show 2>/dev/null || ip addr show
elif command -v ifconfig >/dev/null 2>&1; then
  ifconfig -a
else
  echo "UNSUPPORTED"
fi
SH;

        $result = $this->executor->executeSingle($config, $command);
        $output = trim((string) $result->stdout);

        if ($output === 'UNSUPPORTED') {
            return [];
        }

        $json = json_decode($output, true);
        if (is_array($json)) {
            return $this->parseIpJson($json);
        }

        return $this->parseIpText($output);
    }

    /**
     * @param array<int, array<string, mixed>> $interfaces
     * @return list<NetworkAddressInfo>
     */
    private function parseIpJson(array $interfaces): array
    {
        $addresses = [];

        foreach ($interfaces as $interface) {
            $name = (string) ($interface['ifname'] ?? 'unknown');
            $mac = isset($interface['address']) ? (string) $interface['address'] : null;

            foreach ((array) ($interface['addr_info'] ?? []) as $addr) {
                $family = (string) ($addr['family'] ?? '');
                $address = (string) ($addr['local'] ?? '');
                if ($address === '') {
                    continue;
                }

                $isV6 = $family === 'inet6';
                $prefix = isset($addr['prefixlen']) ? (int) $addr['prefixlen'] : null;
                $calc = $this->calculateSubnet($address, $isV6 ? 'ipv6' : 'ipv4', $prefix);

                $addresses[] = new NetworkAddressInfo(
                    interface: $name,
                    address: $address,
                    family: $isV6 ? 'ipv6' : 'ipv4',
                    macAddress: $mac,
                    loopback: $this->isLoopback($address),
                    linkLocal: $this->isLinkLocal($address),
                    private: $this->isPrivate($address),
                    public: $this->isPublic($address),
                    scope: isset($addr['scope']) ? (string) $addr['scope'] : null,
                    secondary: (bool) ($addr['secondary'] ?? false),
                    cidr: $calc['cidr'],
                    netmask: $calc['netmask'],
                    subnet: $calc['subnet'],
                    broadcast: $calc['broadcast'],
                );
            }
        }

        return $addresses;
    }

    /**
     * @return list<NetworkAddressInfo>
     */
    private function parseIpText(string $output): array
    {
        $addresses = [];
        $currentInterface = null;
        $currentMac = null;

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\d+:\s+(\S+):/', $line, $matches) === 1) {
                $currentInterface = $matches[1];
                if (preg_match('/link\/ether\s+([0-9a-f:]{17})/i', $line, $macMatches) === 1) {
                    $currentMac = $macMatches[1];
                } elseif (preg_match('/ether\s+([0-9a-f:]{17})/i', $line, $macMatches) === 1) {
                    $currentMac = $macMatches[1];
                }
                continue;
            }

            if ($currentInterface === null) {
                continue;
            }

            if (preg_match('/\s+inet6?\s+(\S+)/', $line, $matches) === 1) {
                $parts = explode('/', $matches[1]);
                $address = $parts[0];
                $prefix = isset($parts[1]) ? (int) $parts[1] : null;
                $isV6 = str_contains($line, 'inet6');
                $calc = $this->calculateSubnet($address, $isV6 ? 'ipv6' : 'ipv4', $prefix);

                $addresses[] = new NetworkAddressInfo(
                    interface: $currentInterface,
                    address: $address,
                    family: $isV6 ? 'ipv6' : 'ipv4',
                    macAddress: $currentMac,
                    loopback: $this->isLoopback($address),
                    linkLocal: $this->isLinkLocal($address),
                    private: $this->isPrivate($address),
                    public: $this->isPublic($address),
                    scope: null,
                    secondary: false,
                    cidr: $calc['cidr'],
                    netmask: $calc['netmask'],
                    subnet: $calc['subnet'],
                    broadcast: $calc['broadcast'],
                );
            }
        }

        return $addresses;
    }

    /**
     * @return array{cidr: ?int, netmask: ?string, subnet: ?string, broadcast: ?string}
     */
    private function calculateSubnet(string $address, string $family, ?int $prefix): array
    {
        $result = ['cidr' => null, 'netmask' => null, 'subnet' => null, 'broadcast' => null];

        if ($prefix === null || $prefix < 0) {
            return $result;
        }

        if ($family === 'ipv4') {
            if ($prefix > 32) {
                return $result;
            }

            $ipLong = ip2long($address);
            if ($ipLong === false) {
                return $result;
            }

            $maskLong = $prefix === 0 ? 0 : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);
            $networkLong = $ipLong & $maskLong;
            $broadcastLong = $networkLong | (~$maskLong & 0xFFFFFFFF);

            $result['cidr'] = $prefix;
            $result['netmask'] = long2ip($maskLong);
            $result['subnet'] = long2ip($networkLong) . '/' . $prefix;
            $result['broadcast'] = long2ip($broadcastLong);

            return $result;
        }

        if ($family === 'ipv6') {
            if ($prefix > 128) {
                return $result;
            }

            $packed = @inet_pton($address);
            if ($packed === false) {
                return $result;
            }

            $maskBytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bits = max(0, min(8, $prefix - $i * 8));
                $maskBytes .= chr($bits === 8 ? 0xFF : ($bits === 0 ? 0x00 : ((0xFF << (8 - $bits)) & 0xFF)));
            }

            $networkBytes = '';
            for ($i = 0; $i < 16; $i++) {
                $networkBytes .= chr(ord($packed[$i]) & ord($maskBytes[$i]));
            }

            $result['cidr'] = $prefix;
            $result['netmask'] = @inet_ntop($maskBytes) ?: null;
            $networkStr = @inet_ntop($networkBytes);
            $result['subnet'] = $networkStr !== false ? $networkStr . '/' . $prefix : null;

            return $result;
        }

        return $result;
    }

    private function isLoopback(string $address): bool
    {
        return str_starts_with($address, '127.') || $address === '::1';
    }

    private function isLinkLocal(string $address): bool
    {
        return str_starts_with($address, '169.254.')
            || str_starts_with(strtolower($address), 'fe80:');
    }

    private function isPrivate(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($address);
            return str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd');
        }

        return false;
    }

    private function isPublic(string $address): bool
    {
        return !$this->isLoopback($address) && !$this->isLinkLocal($address) && !$this->isPrivate($address);
    }

    /**
     * @return list<RouteInfo>
     */
    public function getRoutes(ServerConfig $config): array
    {
        $command = 'if command -v ip >/dev/null 2>&1; then ip -j route show 2>/dev/null; fi';
        $result = $this->executor->executeSingle($config, $command);
        $json = json_decode((string) $result->stdout, true);

        if (!is_array($json)) {
            return [];
        }

        $routes = [];
        foreach ($json as $route) {
            $routes[] = new RouteInfo(
                family: 'ipv4',
                destination: isset($route['dst']) ? (string) $route['dst'] : null,
                gateway: isset($route['gateway']) ? (string) $route['gateway'] : null,
                interface: isset($route['dev']) ? (string) $route['dev'] : null,
                source: isset($route['prefsrc']) ? (string) $route['prefsrc'] : null,
                default: (($route['dst'] ?? '') === 'default'),
            );
        }

        return $routes;
    }

    public function getDnsConfig(ServerConfig $config): DnsConfigInfo
    {
        $result = $this->executor->executeSingle($config, 'cat /etc/resolv.conf 2>/dev/null');
        $nameservers = [];
        $search = [];

        foreach (explode("\n", (string) $result->stdout) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'nameserver ')) {
                $nameservers[] = trim(substr($line, 11));
            } elseif (str_starts_with($line, 'search ')) {
                $search = array_values(array_filter(explode(' ', trim(substr($line, 7)))));
            }
        }

        return new DnsConfigInfo(
            nameservers: $nameservers,
            searchDomains: $search,
            source: $result->success ? '/etc/resolv.conf' : null,
            hosts: $this->getHostsEntries($config),
        );
    }

    /**
     * @return list<array{ip: string, hostnames: list<string>}>
     */
    private function getHostsEntries(ServerConfig $config): array
    {
        $result = $this->executor->executeSingle($config, 'cat /etc/hosts 2>/dev/null');
        $entries = [];

        foreach (explode("\n", (string) $result->stdout) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $commentPos = strpos($line, '#');
            if ($commentPos !== false) {
                $line = trim(substr($line, 0, $commentPos));
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 2) {
                continue;
            }

            $ip = array_shift($parts);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $entries[] = ['ip' => $ip, 'hostnames' => array_values($parts)];
        }

        return $entries;
    }
}
