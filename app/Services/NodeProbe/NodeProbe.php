<?php

namespace App\Services\NodeProbe;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeClassification;

class NodeProbe
{
    public function __construct(private PublicEndpoint $endpoint, private ProbeOutbound $outbound, private ProxyRunner $runner) {}

    public function probe(Server $node, string $mode, string $target = 'cloudflare', ?User $user = null): array
    {
        $base = ['node_id' => $node->id, 'mode' => $mode, 'target' => $mode === 'proxy' ? $target : null, 'tested_at' => time(), 'latency_ms' => null];
        if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', (string) $node->port, $ports)
            || (int) $ports[1] < 1 || (int) $ports[1] > 65535 || (isset($ports[2]) && ((int) $ports[2] < (int) $ports[1] || (int) $ports[2] > 65535))) {
            return $base + ['state' => 'blocked', 'stage' => 'address', 'message' => '节点端口格式无效'];
        }
        $port = (int) $ports[1]; $base['port'] = $port;
        if ($mode === 'tcp' && (in_array(NodeClassification::transport($node), ['quic', 'udp'], true))) {
            return $base + ['state' => 'unsupported', 'stage' => 'tcp', 'message' => '此节点使用 UDP/QUIC，TCP 结果不能判断可用性，请选择代理实测'];
        }
        if ($mode === 'proxy' && !$this->runner->available()) return $base + ['state' => 'error', 'stage' => 'engine', 'message' => '当前环境缺少代理测试内核，请升级包含测试内核的镜像'];
        try {
            $addresses = $this->endpoint->addresses($node->host);
        } catch (\Throwable $error) {
            return $base + ['state' => 'blocked', 'stage' => 'address', 'message' => $error instanceof \DomainException ? $error->getMessage() : '节点地址解析失败'];
        }
        if ($mode === 'tcp') return array_replace($base, $this->tcp($addresses, $port));
        try {
            $outbound = $this->outbound->build($node, $user, $addresses[0], $port);
        } catch (\UnexpectedValueException $error) {
            return $base + ['state' => 'skipped', 'stage' => 'credentials', 'message' => $error->getMessage()];
        } catch (\DomainException $error) {
            return $base + ['state' => 'unsupported', 'stage' => 'config', 'message' => $error->getMessage()];
        } catch (\Throwable $error) {
            return $base + ['state' => 'unsupported', 'stage' => 'config', 'message' => '无法生成测试配置，请检查节点协议参数'];
        }
        $url = config("node_probe.targets.{$target}.url");
        if (!$url) return $base + ['state' => 'error', 'stage' => 'target', 'message' => '测试目标不存在'];
        return array_replace($base, $this->runner->run($outbound, $url));
    }

    protected function tcp(array $addresses, int $port): array
    {
        $deadline = microtime(true) + 4;
        foreach (array_slice($addresses, 0, 3) as $address) {
            $remaining = $deadline - microtime(true); if ($remaining <= 0) break;
            $ip = str_contains($address, ':') ? "[{$address}]" : $address;
            $start = microtime(true);
            $socket = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $error, min(2, $remaining));
            if ($socket) {
                fclose($socket);
                return ['state' => 'ok', 'stage' => 'tcp', 'message' => 'TCP 端口可连接（尚未验证代理认证和转发）', 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
            }
        }
        return ['state' => 'failed', 'stage' => 'tcp', 'message' => 'TCP 端口连接失败或超时'];
    }
}
