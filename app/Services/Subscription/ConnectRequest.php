<?php

namespace App\Services\Subscription;

/** Strict HTTP CONNECT parsing; the public gateway never accepts unauthenticated forwarding. */
class ConnectRequest
{
    public static function parse(string $buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if (($end === false && strlen($buffer) > 16384) || $end > 16384) throw new \DomainException('请求头过长');
        if ($end === false) return null;
        $lines = explode("\r\n", substr($buffer, 0, $end));
        if (!preg_match('/^CONNECT (\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+):([0-9]{1,5}) HTTP\/1\.[01]$/D', array_shift($lines), $matches)) {
            throw new \DomainException('仅支持 CONNECT');
        }
        $host = trim($matches[1], '[]'); $port = (int) $matches[2];
        if ($port < 1 || $port > 65535 || (!filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) throw new \DomainException('目标地址无效');
        $headers = [];
        foreach ($lines as $line) {
            if (!preg_match('/^([a-zA-Z0-9-]+):[ \t]*([^\r\n]*)$/D', $line, $header)) throw new \DomainException('请求头无效');
            $key = strtolower($header[1]);
            if (isset($headers[$key])) throw new \DomainException('请求头重复');
            $headers[$key] = trim($header[2]);
        }
        if (!preg_match('/^Basic ([a-zA-Z0-9+\/=]+)$/iD', $headers['proxy-authorization'] ?? '', $auth)) throw new \DomainException('需要认证');
        $decoded = base64_decode($auth[1], true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) throw new \DomainException('认证无效');
        [$username, $password] = explode(':', $decoded, 2);
        if (!hash_equals($username, $password) || !preg_match('/^([a-fA-F0-9-]{36})\.([1-9][0-9]{0,18})$/D', $username, $credential)) throw new \DomainException('认证无效');
        return ['credential' => $credential[1], 'node_id' => (int) $credential[2],
            'target' => (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . $port,
            'remaining' => substr($buffer, $end + 4)];
    }
}
