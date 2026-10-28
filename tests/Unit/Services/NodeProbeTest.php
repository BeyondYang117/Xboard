<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeProbe\NodeProbe;
use App\Services\NodeProbe\ProbeOutbound;
use App\Services\NodeProbe\ProbeResults;
use App\Services\NodeProbe\ProxyRunner;
use App\Services\NodeProbe\PublicEndpoint;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class NodeProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        config()->set('cache.default', 'array');
    }

    private function node(array $attributes = []): Server
    {
        $node = new Server();
        $node->forceFill(array_merge(['id' => 1, 'name' => 'Test', 'type' => 'trojan', 'host' => 'node.example', 'port' => '443', 'protocol_settings' => ['tls' => 1, 'network' => 'tcp'], 'group_ids' => ['2'], 'external_source' => 'source', 'external_password' => 'private-password'], $attributes));
        return $node;
    }

    public function test_public_endpoint_rejects_private_reserved_and_mixed_answers(): void
    {
        foreach ([['127.0.0.1'], ['::1'], ['::ffff:127.0.0.1'], ['169.254.169.254'], ['198.18.0.1'], ['8.8.8.8', '10.0.0.1']] as $answers) {
            $endpoint = new class($answers) extends PublicEndpoint {
                public function __construct(private array $answers) {}
                protected function resolve(string $host): array { return $this->answers; }
            };
            try { $endpoint->addresses('node.example'); $this->fail('Must reject non-public answers'); }
            catch (\DomainException $error) { $this->assertStringContainsString('公网', $error->getMessage()); }
        }
        $this->assertFalse(PublicEndpoint::isPublic('224.1.1.1'));
        $this->assertTrue(PublicEndpoint::isPublic('1.1.1.1'));
    }

    public function test_outbound_preserves_auth_and_tls_hostname_while_pinning_public_ip(): void
    {
        $node = $this->node();
        $outbound = (new ProbeOutbound())->build($node, null, '8.8.8.8', 443);
        $this->assertSame('8.8.8.8', $outbound['server']);
        $this->assertSame('node.example', $outbound['tls']['server_name']);
        $this->assertSame('private-password', $outbound['password']);
        $this->assertSame('probe', $outbound['tag']);
        $node->protocol_settings = ['tls' => 2, 'network' => 'grpc', 'network_settings' => ['serviceName' => 'rpc'], 'reality_settings' => ['server_name' => 'tls.example', 'public_key' => 'public', 'short_id' => 'abcd']];
        $outbound = (new ProbeOutbound())->build($node, null, '8.8.8.8', 443);
        $this->assertSame('tls.example', $outbound['tls']['server_name']);
        $this->assertSame('public', $outbound['tls']['reality']['public_key']);
        $this->assertSame('grpc', $outbound['transport']['type']);
    }

    public function test_self_hosted_probe_requires_valid_user_in_permission_group(): void
    {
        $node = $this->node(['external_source' => null, 'external_password' => null]);
        $builder = new ProbeOutbound();
        try { $builder->build($node, null, '8.8.8.8', 443); $this->fail('Must require test user'); }
        catch (\UnexpectedValueException $error) { $this->assertStringContainsString('用户 ID', $error->getMessage()); }
        $user = new User(); $user->forceFill(['uuid' => 'test-user-uuid', 'group_id' => 2, 'transfer_enable' => 10000, 'u' => 0, 'd' => 0, 'banned' => false]);
        $this->assertSame('test-user-uuid', $builder->build($node, $user, '8.8.8.8', 443)['password']);
        $user->group_id = 3;
        $this->expectException(\UnexpectedValueException::class);
        $builder->build($node, $user, '8.8.8.8', 443);
    }

    public function test_udp_nodes_are_skipped_for_tcp_without_network_io(): void
    {
        $endpoint = Mockery::mock(PublicEndpoint::class); $endpoint->shouldNotReceive('addresses');
        $runner = Mockery::mock(ProxyRunner::class); $runner->shouldNotReceive('run');
        $result = (new NodeProbe($endpoint, new ProbeOutbound(), $runner))->probe($this->node(['type' => 'hysteria', 'protocol_settings' => ['version' => 2]]), 'tcp');
        $this->assertSame('unsupported', $result['state']);
        $this->assertNull($result['latency_ms']);
    }

    public function test_proxy_failure_is_not_replaced_with_tcp_success_or_direct_fallback(): void
    {
        $endpoint = Mockery::mock(PublicEndpoint::class); $endpoint->shouldReceive('addresses')->once()->with('node.example')->andReturn(['8.8.8.8']);
        $runner = Mockery::mock(ProxyRunner::class); $runner->shouldReceive('available')->once()->andReturnTrue();
        $runner->shouldReceive('run')->once()->withArgs(fn ($outbound, $url) => $outbound['server'] === '8.8.8.8' && $outbound['password'] === 'private-password' && str_starts_with($url, 'https://'))->andReturn(['state' => 'failed', 'stage' => 'proxy', 'message' => '代理请求失败']);
        $result = (new NodeProbe($endpoint, new ProbeOutbound(), $runner))->probe($this->node(), 'proxy');
        $this->assertSame('failed', $result['state']);
        $this->assertStringNotContainsString('private-password', json_encode($result));
    }

    public function test_cached_results_expire_when_connection_configuration_changes(): void
    {
        $node = $this->node();
        ProbeResults::put($node, ['mode' => 'proxy', 'state' => 'ok']);
        $this->assertSame('ok', ProbeResults::get($node, 'proxy')['state']);
        $node->host = 'changed.example';
        $this->assertNull(ProbeResults::get($node, 'proxy'));
        $this->assertNull(ProbeResults::get($node, 'tcp'));
    }

    public function test_missing_engine_is_environment_error_not_bad_node(): void
    {
        $endpoint = Mockery::mock(PublicEndpoint::class); $endpoint->shouldNotReceive('addresses');
        $runner = Mockery::mock(ProxyRunner::class); $runner->shouldReceive('available')->andReturnFalse();
        $result = (new NodeProbe($endpoint, new ProbeOutbound(), $runner))->probe($this->node(), 'proxy');
        $this->assertSame('error', $result['state']);
        $this->assertSame('engine', $result['stage']);
    }
}
