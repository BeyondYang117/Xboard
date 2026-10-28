<?php

namespace App\Services\Subscription;

use App\Exceptions\ApiException;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Yaml;

class SubscriptionParser
{
    public function parse(string $content): array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content));
        if (strlen($content) > SubscriptionFetcher::MAX_BYTES) {
            throw new ApiException('订阅内容超过 2 MB 限制', 422);
        }
        if (!str_contains($content, '://') && !str_contains($content, 'proxies')) {
            try {
                $content = trim($this->decode($content));
            } catch (\InvalidArgumentException $e) {
                throw new ApiException('订阅格式无效，请使用节点链接订阅或 Clash YAML', 422);
            }
        }
        $isLinks = preg_match('/^[a-z0-9]+:\/\//i', $content);
        try {
            $entries = $isLinks ? preg_split('/\r\n|\r|\n/', $content) : (Yaml::parse($content)['proxies'] ?? null);
        } catch (\Throwable $e) {
            throw new ApiException('订阅格式无效，请使用节点链接订阅或 Clash YAML', 422);
        }
        if (!is_array($entries) || !$entries || count($entries) > 1000) {
            throw new ApiException('订阅必须包含 1 至 1000 个节点', 422);
        }
        $nodes = [];
        $skipped = [];
        foreach ($entries as $index => $entry) {
            if (is_string($entry) && trim($entry) === '') {
                continue;
            }
            try {
                $proxy = $isLinks ? $this->fromUri(trim($entry)) : $entry;
                if (!is_array($proxy)) {
                    throw new \InvalidArgumentException('节点格式无效');
                }
                $node = $this->fromClash($proxy);
                // Keep identity stable across password rotation and changes to transport settings.
                $key = hash('sha256', json_encode([$node['type'], $node['name'], $node['host'], $node['port']]));
                $nodes[$key] = $node + ['key' => $key];
            } catch (\InvalidArgumentException $e) {
                $skipped[] = ['index' => $index + 1, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                $skipped[] = ['index' => $index + 1, 'reason' => '节点参数格式无效'];
            }
        }
        if (!$nodes) {
            throw new ApiException('未找到可导入的节点', 422, $skipped);
        }
        return ['nodes' => array_values($nodes), 'skipped' => $skipped];
    }

    private function decode(string $value): string
    {
        $value = preg_replace('/\s+/', '', $value);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException('Base64 内容无效');
        }
        return $decoded;
    }

    private function fromUri(string $uri): array
    {
        $scheme = strtolower(strstr($uri, '://', true) ?: '');
        if ($scheme === 'vmess') {
            $v = json_decode($this->decode(substr($uri, 8)), true, 32, JSON_THROW_ON_ERROR);
            if ((int) ($v['aid'] ?? 0) !== 0 || !in_array($v['scy'] ?? 'auto', ['auto', ''], true)) {
                throw new \InvalidArgumentException('暂不支持旧版 VMess alterId 或自定义加密');
            }
            $network = $v['net'] ?? 'tcp';
            $p = [
                'type' => 'vmess', 'name' => $v['ps'] ?? '', 'server' => $v['add'] ?? '',
                'port' => $v['port'] ?? 0, 'uuid' => $v['id'] ?? '', 'network' => $network,
                'tls' => ($v['tls'] ?? '') === 'tls', 'servername' => $v['sni'] ?? '',
                'client-fingerprint' => $v['fp'] ?? '',
                'skip-cert-verify' => filter_var($v['allowInsecure'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            if (!empty($v['alpn'])) {
                throw new \InvalidArgumentException('暂不支持指定 ALPN');
            }
            if (($v['type'] ?? 'none') === 'http' && $network === 'tcp') {
                $p['network'] = 'http';
                $p['http-opts'] = ['path' => explode(',', $v['path'] ?? '/'), 'headers' => ['Host' => explode(',', $v['host'] ?? '')]];
            } elseif ($network === 'ws') {
                $p['ws-opts'] = ['path' => $v['path'] ?? '/', 'headers' => ['Host' => $v['host'] ?? '']];
            } elseif ($network === 'grpc') {
                $p['grpc-opts'] = ['grpc-service-name' => $v['path'] ?? ''];
            } elseif ($network === 'h2') {
                $p['h2-opts'] = ['path' => $v['path'] ?? '/', 'host' => explode(',', $v['host'] ?? '')];
            }
            return $p;
        }
        if ($scheme === 'ss') {
            // SIP002 and legacy base64(method:password@host:port).
            $value = substr($uri, 5);
            [$value, $fragment] = array_pad(explode('#', $value, 2), 2, '');
            [$value, $query] = array_pad(explode('?', $value, 2), 2, '');
            $value = rtrim($value, '/');
            if (!str_contains($value, '@')) {
                $value = $this->decode($value);
            }
            $at = strrpos($value, '@');
            if ($at === false) {
                throw new \InvalidArgumentException('SS 节点格式无效');
            }
            $auth = substr($value, 0, $at);
            $auth = str_contains($auth, ':') ? rawurldecode($auth) : $this->decode($auth);
            [$cipher, $password] = array_pad(explode(':', $auth, 2), 2, '');
            $address = parse_url('ss://' . substr($value, $at + 1));
            parse_str($query, $q);
            $p = ['type' => 'ss', 'name' => rawurldecode($fragment), 'server' => $address['host'] ?? '',
                'port' => $address['port'] ?? 0, 'cipher' => $cipher, 'password' => $password];
            if (!empty($q['plugin'])) {
                [$plugin, $opts] = array_pad(explode(';', $q['plugin'], 2), 2, '');
                $p['plugin'] = $plugin;
                $p['_plugin_opts'] = $opts;
            }
            return $p;
        }
        if (!in_array($scheme, ['vless', 'trojan', 'hysteria2', 'hy2', 'anytls'], true)) {
            throw new \InvalidArgumentException('暂不支持该节点协议');
        }
        $v = parse_url($uri);
        if (!$v) {
            throw new \InvalidArgumentException('节点链接无效');
        }
        parse_str($v['query'] ?? '', $q);
        foreach (['alpn', 'ech', 'encryption', 'mport', 'ports', 'pinSHA256', 'packetEncoding', 'headerType'] as $unsupported) {
            if (!empty($q[$unsupported]) && $q[$unsupported] !== 'none') {
                throw new \InvalidArgumentException('节点包含暂不支持的参数：' . $unsupported);
            }
        }
        $auth = rawurldecode($v['user'] ?? '');
        if (!in_array($q['security'] ?? 'none', ['none', 'tls', 'reality', ''], true)) {
            throw new \InvalidArgumentException('暂不支持该安全传输设置');
        }
        if (isset($v['pass'])) {
            $auth .= ':' . rawurldecode($v['pass']);
        }
        $p = [
            'type' => $scheme === 'hy2' ? 'hysteria2' : $scheme,
            'name' => rawurldecode($v['fragment'] ?? ''), 'server' => $v['host'] ?? '',
            'port' => $v['port'] ?? (in_array($scheme, ['hy2', 'hysteria2', 'anytls', 'trojan'], true) ? 443 : 0), 'password' => $auth, 'uuid' => $auth,
            'network' => $q['type'] ?? 'tcp',
            'tls' => ($q['security'] ?? ($scheme === 'vless' ? 'none' : 'tls')) !== 'none',
            'sni' => $q['sni'] ?? $q['peer'] ?? $q['servername'] ?? '',
            'skip-cert-verify' => filter_var($q['insecure'] ?? $q['allowInsecure'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'client-fingerprint' => $q['fp'] ?? '', 'flow' => $q['flow'] ?? '',
        ];
        if (($q['security'] ?? '') === 'reality') {
            $p['reality-opts'] = ['public-key' => $q['pbk'] ?? '', 'short-id' => $q['sid'] ?? ''];
        }
        if ($p['network'] === 'ws') {
            $p['ws-opts'] = ['path' => $q['path'] ?? '/', 'headers' => ['Host' => $q['host'] ?? '']];
        } elseif ($p['network'] === 'grpc') {
            $p['grpc-opts'] = ['grpc-service-name' => $q['serviceName'] ?? ''];
        } elseif (in_array($p['network'], ['http', 'h2'], true)) {
            $p['network'] = 'h2';
            $p['h2-opts'] = ['path' => $q['path'] ?? '/', 'host' => explode(',', $q['host'] ?? '')];
        }
        if (isset($q['obfs']) && $q['obfs'] !== 'none') {
            $p['obfs'] = $q['obfs'];
            $p['obfs-password'] = $q['obfs-password'] ?? '';
        }
        return $p;
    }

    private function fromClash(array $p): array
    {
        $type = match ($p['type'] ?? '') {
            'ss' => Server::TYPE_SHADOWSOCKS,
            'hysteria2' => Server::TYPE_HYSTERIA,
            'vmess', 'vless', 'trojan', 'anytls' => $p['type'],
            default => throw new \InvalidArgumentException('暂不支持该节点协议'),
        };
        foreach (['alpn', 'ech-opts', 'smux', 'ports', 'port-hopping', 'dialer-proxy', 'ip-version', 'packet-encoding', 'encryption', 'fingerprint', 'ca', 'ca-str', 'certificate'] as $key) {
            if ($key === 'encryption' && ($p[$key] ?? null) === 'none') {
                continue;
            }
            if (!empty($p[$key])) {
                throw new \InvalidArgumentException('节点包含暂不支持的参数：' . $key);
            }
        }
        $host = trim((string) ($p['server'] ?? ''), '[]');
        $password = $p[in_array($type, ['vmess', 'vless'], true) ? 'uuid' : 'password'] ?? '';
        $name = ($p['name'] ?? '') ?: $host;
        $values = ['host' => $host, 'port' => $p['port'] ?? null, 'password' => $password, 'name' => $name];
        if (Validator::make($values, [
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9._:\-]+$/'],
            'port' => 'required|integer|between:1,65535', 'password' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
        ])->fails()) {
            throw new \InvalidArgumentException('节点名称、地址、端口或认证信息无效');
        }
        if (in_array($type, ['vmess', 'vless'], true)
            && !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $password)) {
            throw new \InvalidArgumentException('节点 UUID 无效');
        }
        $tls = ['server_name' => $p['sni'] ?? $p['servername'] ?? null, 'allow_insecure' => (bool) ($p['skip-cert-verify'] ?? false)];
        $settings = [];
        if ($type === Server::TYPE_SHADOWSOCKS) {
            if (empty($p['cipher']) || !is_string($p['cipher'])) {
                throw new \InvalidArgumentException('SS 加密方式无效');
            }
            $settings['cipher'] = $p['cipher'];
            if (!empty($p['plugin'])) {
                $settings['plugin'] = $p['plugin'];
                $settings['plugin_opts'] = $p['_plugin_opts'] ?? $this->pluginOptions($p['plugin-opts'] ?? []);
            }
        } elseif ($type === Server::TYPE_HYSTERIA) {
            $settings = ['version' => 2, 'tls' => $tls, 'bandwidth' => ['up' => $p['up'] ?? null, 'down' => $p['down'] ?? null]];
            if (!empty($p['obfs'])) {
                if ($p['obfs'] !== 'salamander' || empty($p['obfs-password'])) {
                    throw new \InvalidArgumentException('Hysteria2 混淆参数无效');
                }
                $settings['obfs'] = ['open' => true, 'type' => 'salamander', 'password' => $p['obfs-password']];
            }
        } elseif ($type === Server::TYPE_ANYTLS) {
            if (!empty($p['client-fingerprint'])) {
                throw new \InvalidArgumentException('暂不支持 AnyTLS 自定义 TLS 指纹');
            }
            $settings['tls'] = $tls;
        } else {
            if ($type === 'trojan' && isset($p['tls']) && !$p['tls']) {
                throw new \InvalidArgumentException('暂不支持关闭 TLS 的 Trojan 节点');
            }
            if ($type === 'vmess' && ((int) ($p['alterId'] ?? 0) !== 0 || ($p['cipher'] ?? 'auto') !== 'auto')) {
                throw new \InvalidArgumentException('暂不支持旧版 VMess alterId 或自定义加密');
            }
            $settings = ['tls' => (int) ($p['tls'] ?? ($type === 'trojan')), 'tls_settings' => $tls];
            $network = $p['network'] ?? 'tcp';
            $settings['network'] = $network;
            $settings['network_settings'] = match ($network) {
                'tcp' => [],
                'ws' => $p['ws-opts'] ?? [],
                'grpc' => ['serviceName' => $p['grpc-opts']['grpc-service-name'] ?? ''],
                'h2' => $p['h2-opts'] ?? [],
                'http' => ['header' => ['type' => 'http', 'request' => $p['http-opts'] ?? []]],
                default => throw new \InvalidArgumentException('暂不支持该传输方式'),
            };
            if ($network === 'http') {
                $settings['network'] = 'tcp';
            }
            if ($network === 'ws') {
                foreach (['max-early-data', 'early-data-header-name', 'v2ray-http-upgrade'] as $key) {
                    if (!empty($settings['network_settings'][$key])) {
                        throw new \InvalidArgumentException('暂不支持 WebSocket early-data 或 HTTP Upgrade');
                    }
                }
                $headers = $settings['network_settings']['headers'] ?? [];
                if (array_diff(array_keys($headers), ['Host'])) {
                    throw new \InvalidArgumentException('暂不支持额外的 WebSocket 请求头');
                }
            }
            if (!empty($p['client-fingerprint'])) {
                $settings['utls'] = ['enabled' => true, 'fingerprint' => $p['client-fingerprint']];
            }
            if (!empty($p['reality-opts'])) {
                if ($type === 'vmess' || empty($p['reality-opts']['public-key'])) {
                    throw new \InvalidArgumentException('Reality 参数无效');
                }
                $settings['tls'] = 2;
                $settings['reality_settings'] = $tls + [
                    'public_key' => $p['reality-opts']['public-key'], 'short_id' => $p['reality-opts']['short-id'] ?? '',
                ];
            }
            if ($type === 'vless') {
                $settings['flow'] = $p['flow'] ?? null;
            }
        }
        $server = new Server(['type' => $type, 'protocol_settings' => $settings]);
        return ['name' => $name, 'type' => $type, 'host' => $host, 'port' => (int) $p['port'],
            'server_port' => (int) $p['port'], 'protocol_settings' => $server->protocol_settings,
            'external_password' => $password];
    }

    private function pluginOptions(array $options): string
    {
        $parts = [];
        foreach ($options as $key => $value) {
            if ($value === true) {
                $parts[] = $key;
            } elseif ($value !== false && $value !== null) {
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException('SS 插件参数无效');
                }
                $parts[] = $key . '=' . $value;
            }
        }
        return implode(';', $parts);
    }
}
