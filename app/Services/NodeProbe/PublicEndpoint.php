<?php

namespace App\Services\NodeProbe;

use Symfony\Component\HttpFoundation\IpUtils;

class PublicEndpoint
{
    public function addresses(string $host): array
    {
        $host = trim($host, '[]');
        if (!$host || strlen($host) > 253 || preg_match('/[\s\/@?#\\\\]/', $host)) throw new \DomainException('节点地址格式无效');
        $addresses = $this->resolve($host);
        if (!$addresses) throw new \DomainException('无法解析节点地址');
        foreach ($addresses as $address) if (!self::isPublic($address)) throw new \DomainException('测试仅支持公网节点地址，内网或保留地址已跳过');
        // Prefer IPv4 when both families exist; all answers were validated first.
        usort($addresses, fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
        return array_values(array_unique($addresses));
    }

    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: [])));
    }

    public static function isPublic(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (str_contains($address, ':')) return IpUtils::checkIp($address, '2000::/3') && !IpUtils::checkIp($address, ['2001::/23', '2001:db8::/32', '2002::/16']);
        return !IpUtils::checkIp($address, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4']);
    }
}
