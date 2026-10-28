<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Services\Subscription\SubscriptionParser;
use Tests\TestCase;

class SubscriptionParserTest extends TestCase
{
    private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    public function test_base64_subscription_preserves_credentials_ipv6_and_reality(): void
    {
        $links = 'vless://' . self::UUID . '@[2001:db8::1]:443?security=reality&type=tcp&sni=example.com&pbk=public-key&sid=ab&fp=chrome#Test%20Node';
        $node = (new SubscriptionParser())->parse(base64_encode($links))['nodes'][0];
        $this->assertSame('Test Node', $node['name']);
        $this->assertSame('2001:db8::1', $node['host']);
        $this->assertSame(self::UUID, $node['external_password']);
        $this->assertSame(2, $node['protocol_settings']['tls']);
        $proxy = ClashMeta::buildVless($node['external_password'], $node);
        $this->assertSame(self::UUID, $proxy['uuid']);
        $this->assertSame('public-key', $proxy['reality-opts']['public-key']);
        $this->assertSame('example.com', $proxy['servername']);
    }

    public function test_ss_sip002_legacy_and_2022_passwords_round_trip(): void
    {
        $auth = '2022-blake3-aes-128-gcm:server-key:user-key';
        foreach (['ss://' . base64_encode($auth) . '@example.com:8388#SS', 'ss://' . base64_encode($auth . '@example.com:8388') . '#SS'] as $link) {
            $node = (new SubscriptionParser())->parse($link)['nodes'][0];
            $this->assertSame('server-key:user-key', $node['external_password']);
            $node['password'] = $node['external_password'];
            $export = General::buildShadowsocks($node['password'], $node);
            $again = (new SubscriptionParser())->parse($export)['nodes'][0];
            $this->assertSame($node['external_password'], $again['external_password']);
        }
    }

    public function test_clash_yaml_preserves_ws_tls_and_plugin_settings(): void
    {
        $yaml = "proxies:\n  - name: WS\n    type: vmess\n    server: example.com\n    port: 443\n    uuid: " . self::UUID . "\n    tls: true\n    servername: tls.example.com\n    network: ws\n    ws-opts:\n      path: /ws\n      headers: {Host: ws.example.com}\n  - name: SS\n    type: ss\n    server: example.com\n    port: 8080\n    cipher: aes-128-gcm\n    password: fixed-secret\n    plugin: v2ray-plugin\n    plugin-opts: {mode: websocket, tls: true, host: ss.example.com}\n";
        $parsed = (new SubscriptionParser())->parse($yaml);
        $this->assertCount(2, $parsed['nodes']);
        $ws = $parsed['nodes'][0];
        $proxy = ClashMeta::buildVmess($ws['external_password'], $ws);
        $this->assertSame('/ws', $proxy['ws-opts']['path']);
        $this->assertSame('ws.example.com', $proxy['ws-opts']['headers']['Host']);
        $this->assertSame('tls.example.com', $proxy['servername']);
        $this->assertSame('mode=websocket;tls;host=ss.example.com', $parsed['nodes'][1]['protocol_settings']['plugin_opts']);
    }

    public function test_vmess_json_and_trojan_grpc(): void
    {
        $link = 'vmess://' . base64_encode(json_encode(['ps' => 'VM', 'add' => 'example.com', 'port' => '443', 'id' => self::UUID, 'net' => 'ws', 'path' => '/v', 'host' => 'ws.example.com', 'tls' => 'tls']));
        $parsed = (new SubscriptionParser())->parse($link . "\n" . 'trojan://fixed%3Asecret@example.com:443?type=grpc&serviceName=grpc&sni=example.com#TR');
        $this->assertCount(2, $parsed['nodes']);
        $this->assertSame('/v', $parsed['nodes'][0]['protocol_settings']['network_settings']['path']);
        $this->assertSame('fixed:secret', $parsed['nodes'][1]['external_password']);
        $this->assertSame('grpc', $parsed['nodes'][1]['protocol_settings']['network_settings']['serviceName']);
    }

    public function test_hysteria2_and_anytls(): void
    {
        $parsed = (new SubscriptionParser())->parse("hy2://auth@example.com:443?sni=sni.example.com&obfs=salamander&obfs-password=obfs&insecure=1#HY\nanytls://pass@example.com:443?sni=tls.example.com#AT");
        $hy = $parsed['nodes'][0];
        $this->assertSame('hysteria', $hy['type']);
        $this->assertSame('obfs', $hy['protocol_settings']['obfs']['password']);
        $this->assertTrue($hy['protocol_settings']['tls']['allow_insecure']);
        $this->assertSame('tls.example.com', $parsed['nodes'][1]['protocol_settings']['tls']['server_name']);
    }

    public function test_unsupported_and_malformed_entries_are_reported_without_secrets(): void
    {
        $links = "tuic://secret@example.com:443#TU\nvmess://malformed-secret\ntrojan://pass@example.com:443#OK\nvless://" . self::UUID . '@example.com:443?type=xhttp#Unsupported';
        $parsed = (new SubscriptionParser())->parse($links);
        $this->assertCount(1, $parsed['nodes']);
        $this->assertCount(3, $parsed['skipped']);
        $this->assertStringNotContainsString('secret', json_encode($parsed['skipped']));
    }

    public function test_duplicate_nodes_collapse_and_key_survives_password_rotation(): void
    {
        $parser = new SubscriptionParser();
        $parsed = $parser->parse("trojan://old@example.com:443#N\ntrojan://new@example.com:443#N");
        $this->assertCount(1, $parsed['nodes']);
        $this->assertSame('new', $parsed['nodes'][0]['external_password']);
        $old = $parser->parse('trojan://old@example.com:443#N')['nodes'][0];
        $this->assertSame($old['key'], $parsed['nodes'][0]['key']);
    }

    public function test_invalid_subscription_is_a_validation_error(): void
    {
        $this->expectException(ApiException::class);
        (new SubscriptionParser())->parse('<html>error page</html>');
    }

    public function test_reserved_characters_in_password_survive_uri_exports(): void
    {
        $parser = new SubscriptionParser();
        $password = 'p@ss:/?#%+word';
        foreach (['trojan', 'hysteria2', 'anytls'] as $scheme) {
            $node = $parser->parse($scheme . '://' . rawurlencode($password) . '@example.com:443#Node')['nodes'][0];
            $method = match ($scheme) {
                'trojan' => 'buildTrojan', 'hysteria2' => 'buildHysteria', 'anytls' => 'buildAnyTLS',
            };
            foreach ([General::class, Shadowrocket::class] as $class) {
                $export = $class::$method($password, $node);
                $this->assertSame($password, $parser->parse($export)['nodes'][0]['external_password']);
            }
        }
    }

    public function test_all_unsupported_nodes_do_not_succeed_silently(): void
    {
        $this->expectException(ApiException::class);
        (new SubscriptionParser())->parse('tuic://secret@example.com:443');
    }
}
