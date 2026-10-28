<?php

namespace App\Services\NodeProbe;

use App\Models\Server;
use App\Models\User;
use App\Protocols\SingBox;

/** Reuse subscription outbound builders without loading templates or routing rules. */
class ProbeOutbound extends SingBox
{
    public function __construct()
    {
        parent::__construct([], [], 'sing-box', '1.14.4');
    }

    public function build(Server $node, ?User $user, string $ip, int $port): array
    {
        $method = match ($node->type) {
            'shadowsocks' => 'buildShadowsocks', 'vmess' => 'buildVmess', 'vless' => 'buildVless',
            'trojan' => 'buildTrojan', 'hysteria' => 'buildHysteria', 'tuic' => 'buildTuic',
            'anytls' => 'buildAnyTLS', 'socks' => 'buildSocks', 'http' => 'buildHttp',
            default => throw new \DomainException('当前测试内核不支持此协议，可使用 TCP 测试或客户端实测'),
        };
        $settings = $node->protocol_settings ?? [];
        if (!empty($settings['plugin'])) throw new \DomainException('暂不支持使用外部插件的节点实测');
        if (!in_array(data_get($settings, 'network', 'tcp'), ['tcp', 'ws', 'grpc', 'h2', 'httpupgrade', 'quic'], true)) throw new \DomainException('当前测试内核不支持此传输方式');
        if ($node->type === 'tuic' && (int) data_get($settings, 'version', 5) !== 5) throw new \DomainException('代理实测仅支持 TUIC 5');
        if (!$node->is_external) {
            if (!$user) throw new \UnexpectedValueException('自建节点代理测试需指定有效测试用户 ID');
            if ($user->banned || ($user->expired_at && $user->expired_at < time()) || $user->u + $user->d >= $user->transfer_enable
                || !in_array((string) $user->group_id, array_map('strval', $node->group_ids ?? []), true)) {
                throw new \UnexpectedValueException('测试用户已停用、到期、额度不足或不属于节点权限组');
            }
        }
        // Resolve source from persisted attributes instead of the appended accessor. Probe
        // callers may pass a model that has not had computed attributes appended.
        $isExternal = $node->external_source !== null;
        $password = $isExternal ? $node->external_password : $node->generateServerPassword($user);
        if (!$password) throw new \UnexpectedValueException('节点缺少可用的认证信息');
        // Keep the original hostname for SNI/Host defaults, then pin the dial IP.
        $data = $node->toArray();
        $data['port'] = $port; $data['password'] = $password;
        $outbound = $this->{$method}($password, $data);
        if (data_get($outbound, 'tls.enabled') && empty($outbound['tls']['server_name']) && !filter_var($node->host, FILTER_VALIDATE_IP)) $outbound['tls']['server_name'] = $node->host;
        $outbound['server'] = $ip; $outbound['tag'] = 'probe'; $outbound['connect_timeout'] = '4s';
        return $outbound;
    }
}
