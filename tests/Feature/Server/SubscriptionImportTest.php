<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\SubscriptionController;
use App\Models\AdminAuditLog;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerSubscription;
use App\Models\User;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use App\Services\Subscription\SubscriptionFetcher;
use App\Services\Subscription\SubscriptionGateway;
use App\Services\DeviceStateService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SubscriptionImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ServerGroup $group;

    protected function beforeRefreshingDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('cache.stores.redis', ['driver' => 'array']);
        DB::purge('sqlite');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forgetDriver('redis');
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $settings = Mockery::mock(Setting::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $key === 'server_token' ? 'server-token' : $default);
        $this->app->instance(Setting::class, $settings);
        $this->admin = $this->makeUser(true);
        $this->withHeader('Authorization', 'Bearer ' . $this->admin->createToken('test')->plainTextToken);
        $this->group = new ServerGroup();
        $this->group->name = 'External';
        $this->group->save();
    }

    private function makeUser(bool $admin = false): User
    {
        return User::create(['email' => Str::random(8) . '@example.com', 'password' => 'hash', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => $admin, 'transfer_enable' => 1000000, 'expired_at' => time() + 86400]);
    }

    private function preview(string $password = 'upstream-secret'): array
    {
        $mock = Mockery::mock(SubscriptionFetcher::class);
        $mock->shouldReceive('fetch')->once()->with('https://airport.example/sub?token=private-token')
            ->andReturn("trojan://{$password}@node.example:443?sni=tls.example#Node\nss://" . base64_encode('aes-128-gcm:ss-secret') . '@ss.example:8388#SS');
        $this->app->instance(SubscriptionFetcher::class, $mock);
        return $this->postJson(action([SubscriptionController::class, 'preview'], [], false), [
            'subscription_url' => 'https://airport.example/sub?token=private-token',
        ])->assertOk()->assertJsonCount(2, 'data.nodes')->json('data');
    }

    private function import(array $preview, array $overrides = [])
    {
        return $this->postJson(action([SubscriptionController::class, 'import'], [], false), array_replace([
            'preview_token' => $preview['preview_token'], 'node_keys' => array_column($preview['nodes'], 'key'),
            'group_ids' => [$this->group->id], 'show' => true, 'delivery_mode' => 'direct',
        ], $overrides));
    }

    public function test_import_preserves_encrypted_auth_and_reimport_updates_without_duplicates(): void
    {
        $preview = $this->preview();
        $this->assertStringNotContainsString('upstream-secret', json_encode($preview));
        $this->assertStringNotContainsString('private-token', json_encode($preview));
        $this->assertStringNotContainsString('upstream-secret', Cache::get('subscription-preview:' . $preview['preview_token']));
        $this->import($preview)->assertOk()->assertJson(['data' => ['created' => 2, 'updated' => 0]]);
        $server = Server::where('type', 'trojan')->firstOrFail();
        $id = $server->id;
        $this->assertSame('upstream-secret', $server->external_password);
        $this->assertNotSame('upstream-secret', DB::table('v2_server')->where('id', $id)->value('external_password'));
        $this->assertArrayNotHasKey('external_password', $server->toArray());
        $this->assertFalse($server->enabled);
        $this->assertNull($server->machine_id);
        $this->assertNull(ServerService::getServer($id));
        $this->import($this->preview('rotated-secret'))->assertOk()->assertJson(['data' => ['created' => 0, 'updated' => 2]]);
        $this->assertDatabaseCount('v2_server', 2);
        $this->assertSame('rotated-secret', Server::findOrFail($id)->external_password);
        $audit = AdminAuditLog::all()->toJson();
        $this->assertStringNotContainsString('private-token', $audit);
        $this->assertStringNotContainsString($preview['preview_token'], $audit);
    }

    public function test_external_nodes_use_original_password_for_users_and_subscription_outputs(): void
    {
        $this->import($this->preview())->assertOk();
        $user = $this->makeUser();
        $user->group_id = $this->group->id;
        $servers = ServerService::getAvailableServers($user);
        $this->assertCount(2, $servers);
        $this->assertSame('upstream-secret', $servers[0]['password']);
        $this->assertSame('ss-secret', $servers[1]['password']);
        $general = new General($user, $servers);
        $this->assertStringContainsString('trojan://upstream-secret@node.example:443', base64_decode($general->handle()->getContent()));
        $singBox = new SingBox($user, $servers);
        $outbounds = json_decode($singBox->handle()->getContent(), true)['outbounds'];
        $trojan = collect($outbounds)->firstWhere('type', 'trojan');
        $this->assertSame('upstream-secret', $trojan['password']);
        $this->assertSame('tls.example', $trojan['tls']['server_name']);
        $user->group_id = $this->group->id + 100;
        $this->assertSame([], ServerService::getAvailableServers($user));
    }

    public function test_selection_and_hidden_nodes_are_respected(): void
    {
        $preview = $this->preview();
        $this->import($preview, ['node_keys' => [$preview['nodes'][0]['key']], 'show' => false])->assertOk();
        $this->assertDatabaseCount('v2_server', 1);
        $this->admin->group_id = $this->group->id;
        $this->assertSame([], ServerService::getAvailableServers($this->admin));
    }

    public function test_rejects_unknown_selection_and_groups_without_writing(): void
    {
        $preview = $this->preview();
        $this->import($preview, ['node_keys' => [str_repeat('f', 64)]])->assertUnprocessable();
        $this->import($preview, ['group_ids' => [999999]])->assertUnprocessable();
        $this->import($preview, ['group_ids' => []])->assertUnprocessable();
        $this->assertDatabaseCount('v2_server', 0);
    }

    public function test_preview_is_bound_to_admin_and_expires(): void
    {
        $preview = $this->preview();
        $other = $this->makeUser(true);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $other->createToken('other')->plainTextToken);
        $this->import($preview)->assertForbidden();
        Cache::forget('subscription-preview:' . $preview['preview_token']);
        $this->import($preview)->assertUnprocessable();
        $this->assertDatabaseCount('v2_server', 0);
    }

    public function test_requires_admin_for_preview_and_import(): void
    {
        $user = $this->makeUser();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $user->createToken('user')->plainTextToken);
        $this->postJson(action([SubscriptionController::class, 'preview'], [], false), [])->assertForbidden();
        $this->postJson(action([SubscriptionController::class, 'import'], [], false), [])->assertForbidden();
    }

    public function test_external_nodes_cannot_be_assigned_to_a_machine_or_enabled_as_managed_nodes(): void
    {
        $this->import($this->preview())->assertOk();
        $server = Server::firstOrFail();
        $this->postJson(action([ManageController::class, 'update'], [], false), ['id' => $server->id, 'enabled' => true])->assertUnprocessable();
        $this->postJson(action([ManageController::class, 'batchUpdate'], [], false), ['ids' => [$server->id], 'machine_id' => 1])->assertUnprocessable();
        $this->postJson('/api/v2/server/report', ['token' => 'server-token', 'node_id' => $server->id])->assertStatus(400);
    }

    public function test_admin_filters_imported_nodes_without_exposing_credentials(): void
    {
        $this->import($this->preview())->assertOk();
        $url = action([ManageController::class, 'getNodes'], [], false);
        $this->getJson($url . '?' . http_build_query(['node_filter' => ['source' => 'external', 'status' => 'external', 'online_max' => '0']]))
            ->assertOk()->assertJsonCount(2, 'data');
        $response = $this->getJson($url . '?' . http_build_query(['node_filter' => ['keyword' => 'node.example', 'show' => '1']]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('external_password', $response->json('data.0'));
        $this->getJson($url . '?' . http_build_query(['node_filter' => ['status' => '0']]))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url . '?' . http_build_query(['node_filter' => ['port' => '70000']]))->assertStatus(422);
    }

    public function test_classification_is_admin_only_atomic_and_survives_reimport(): void
    {
        $this->import($this->preview())->assertOk();
        $nodes = Server::orderBy('id')->get();
        $url = action([\App\Http\Controllers\V2\Admin\Server\ClassificationController::class, 'batchClassify'], [], false);
        $dimensions = action([\App\Http\Controllers\V2\Admin\Server\ClassificationController::class, 'dimensions'], [], false);
        $id = $nodes[0]->id;
        $this->postJson($url, ['ids' => [$id], 'region' => 'JP', 'management_group' => '亚洲专线', 'provider' => '测试机场', 'add_tags' => ['流媒体']])
            ->assertOk()->assertJsonPath('data.updated', 1);
        $node = Server::findOrFail($id);
        $this->assertSame('JP', $node->region);
        $this->assertSame('亚洲专线', $node->management_group);
        $this->assertSame($nodes[0]->group_ids, $node->group_ids);
        $this->assertNull($nodes[1]->fresh()->management_group);
        $this->assertSame('upstream-secret', $node->external_password);
        $response = $this->getJson($dimensions . '?' . http_build_query(['dimension' => 'protocol', 'node_filter' => ['region' => 'JP']]))
            ->assertOk()->assertJsonPath('data.matched', 1)->assertJsonPath('data.nodes.0.region_origin', 'manual');
        $this->assertStringNotContainsString('upstream-secret', $response->getContent());
        $this->assertStringNotContainsString($node->external_source, $response->getContent());
        $this->assertArrayNotHasKey('management_group', $node->toArray());
        $this->postJson($url, ['ids' => [$id, 999999], 'provider' => 'Should not persist'])->assertStatus(422);
        $this->assertSame('测试机场', $node->fresh()->provider);
        $this->postJson($url, ['ids' => [$id], 'region' => 'INVALID'])->assertStatus(422);
        $this->postJson($url, ['ids' => [$id]])->assertStatus(422);
        $this->import($this->preview('rotated'))->assertOk();
        $this->assertSame('JP', $node->fresh()->region);
        $this->assertSame('亚洲专线', $node->fresh()->management_group);
        $this->postJson($url, ['ids' => [$id], 'region' => null, 'management_group' => null, 'remove_tags' => ['流媒体'], 'add_tags' => ['专线']])->assertOk();
        $this->assertNull($node->fresh()->region);
        $this->assertSame(['外部订阅', '专线'], $node->fresh()->tags);
        $user = $this->makeUser();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $user->createToken('test')->plainTextToken);
        $this->getJson($dimensions)->assertStatus(403);
        $this->postJson($url, ['ids' => [$id], 'region' => 'US'])->assertStatus(403);
    }

    public function test_probe_api_checks_permissions_validation_and_caches_safe_results(): void
    {
        $this->import($this->preview())->assertOk();
        $id = Server::firstOrFail()->id;
        $url = action([\App\Http\Controllers\V2\Admin\Server\ProbeController::class, 'run'], [], false);
        $history = action([\App\Http\Controllers\V2\Admin\Server\ProbeController::class, 'history'], [], false);
        $probe = Mockery::mock(\App\Services\NodeProbe\NodeProbe::class);
        $probe->shouldReceive('probe')->once()->andReturn(['node_id' => $id, 'mode' => 'proxy', 'target' => 'cloudflare', 'state' => 'ok', 'stage' => 'http', 'message' => '代理请求成功', 'latency_ms' => 128, 'tested_at' => time()]);
        $this->app->instance(\App\Services\NodeProbe\NodeProbe::class, $probe);
        $this->postJson($url, ['id' => $id, 'mode' => 'proxy', 'target' => 'cloudflare'])->assertOk()->assertJsonPath('data.state', 'ok');
        $this->postJson($history, ['ids' => [$id]])->assertOk()->assertJsonPath("data.results.{$id}.proxy.latency_ms", 128);
        $this->postJson($url, ['id' => $id, 'mode' => 'proxy', 'target' => 'http://127.0.0.1'])->assertUnprocessable();
        $lock = Cache::lock("node-probe:running:{$id}", 25); $this->assertTrue($lock->get());
        $this->postJson($url, ['id' => $id, 'mode' => 'tcp'])->assertStatus(429); $lock->release();
        $this->assertTrue(Cache::lock("node-probe:slot:0", 25)->get());
        $user = $this->makeUser(); $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $user->createToken('test')->plainTextToken);
        $this->postJson($url, ['id' => $id, 'mode' => 'tcp'])->assertForbidden();
        $this->postJson($history, ['ids' => [$id]])->assertForbidden();
    }

    public function test_managed_nodes_still_use_platform_uuid(): void
    {
        $server = Server::create(['type' => 'trojan', 'name' => 'Managed', 'host' => 'managed.example', 'port' => '443',
            'server_port' => 443, 'rate' => 1, 'group_ids' => [(string) $this->group->id], 'show' => true]);
        $this->assertSame($this->admin->uuid, $server->generateServerPassword($this->admin));
        $this->assertSame($server->id, ServerService::getServer($server->id)->id);
    }

    private function relay(array $overrides = []): array
    {
        $this->import($this->preview(), array_replace(['delivery_mode' => 'relay', 'gateway_host' => 'relay.example.com'], $overrides))->assertOk();
        $devices = Mockery::mock(DeviceStateService::class);
        $devices->shouldReceive('getUsersDevices')->andReturn([]);
        $this->app->instance(DeviceStateService::class, $devices);
        $user = $this->makeUser();
        $user->group_id = $this->group->id; $user->saveQuietly();
        return [ServerSubscription::firstOrFail(), Server::firstOrFail(), $user, app(SubscriptionGateway::class)];
    }

    public function test_source_management_updates_all_nodes_and_deletes_only_its_source(): void
    {
        $this->import($this->preview())->assertOk();
        $source = ServerSubscription::firstOrFail();
        $other = Server::create(['type' => 'trojan', 'name' => 'Local', 'host' => 'local.example', 'port' => '443', 'server_port' => 443, 'rate' => 1]);
        $save = action([SubscriptionController::class, 'saveSubscription'], [], false);
        $this->postJson($save, ['id' => $source->id, 'name' => '我的机场', 'rate' => 2, 'show' => false, 'enabled' => false])->assertOk();
        $this->assertSame('我的机场', $source->fresh()->name);
        $this->assertEquals(2, Server::where('subscription_id', $source->id)->value('rate'));
        $this->assertFalse(Server::where('subscription_id', $source->id)->first()->show);
        $this->getJson(action([SubscriptionController::class, 'subscriptions'], [], false))->assertOk()
            ->assertJsonPath('data.0.nodes_count', 2)->assertJsonPath('data.0.enabled', false)->assertJsonMissingPath('data.0.source');
        $this->postJson(action([SubscriptionController::class, 'deleteSubscription'], [], false), ['id' => $source->id])->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertDatabaseCount('v2_server_subscription', 0);
        $this->assertDatabaseCount('v2_server', 1); $this->assertNotNull($other->fresh());
    }

    public function test_relay_exports_personal_credentials_and_requires_live_gateway(): void
    {
        [$source, $node, $user] = $this->relay(['rate' => 2]);
        $this->assertSame([], ServerService::getAvailableServers($user));
        Cache::put('subscription-gateway:' . $source->id, true, 30);
        $nodes = ServerService::getAvailableServers($user);
        $this->assertCount(2, $nodes);
        $this->assertSame('http', $nodes[0]['type']);
        $this->assertSame('relay.example.com', $nodes[0]['host']);
        $this->assertSame($user->uuid . '.' . $node->id, $nodes[0]['password']);
        $this->assertSame(1, $nodes[0]['protocol_settings']['tls']);
        $this->assertStringNotContainsString('upstream-secret', json_encode($nodes));
        $this->assertStringNotContainsString('node.example', json_encode($nodes));
        $this->assertEquals(2, $node->rate);
        $source->update(['enabled' => false]);
        $this->assertSame([], ServerService::getAvailableServers($user));
    }

    public function test_provider_ip_limit_is_shared_across_nodes_and_released_after_close(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['max_online' => 1]);
        $second = Server::where('id', '!=', $node->id)->firstOrFail();
        $first = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $same = $gateway->open($source->id, $second->id, $user->uuid, '203.0.113.1');
        $this->assertSame(['online_users' => 1, 'online_ips' => 1, 'online_connections' => 2], $gateway->online($source->id));
        try { $gateway->open($source->id, $second->id, $user->uuid, '203.0.113.2'); $this->fail('Should reject provider overflow'); }
        catch (\DomainException $e) { $this->assertSame('机场在线名额已满', $e->getMessage()); }
        $gateway->record($first, 0, 0, true); $gateway->record($same, 0, 0, true);
        $this->assertSame(0, $user->fresh()->online_count);
        $gateway->open($source->id, $second->id, $user->uuid, '203.0.113.2');
        $this->assertSame(1, $user->fresh()->online_count);
    }

    public function test_relay_billing_is_idempotent_and_updates_user_node_provider_and_daily_stats(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['rate' => 2]);
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $this->assertTrue($gateway->record($id, 100, 200));
        $this->assertTrue($gateway->record($id, 100, 200));
        $this->assertFalse($gateway->record($id, 150, 250, true));
        $this->assertFalse($gateway->record($id, 150, 250, true));
        $this->assertSame(300, $user->fresh()->u); $this->assertSame(500, $user->fresh()->d);
        $this->assertSame(150, $node->fresh()->u); $this->assertSame(250, $node->fresh()->d);
        $this->assertSame(150, $source->fresh()->u); $this->assertSame(250, $source->fresh()->d);
        $this->assertDatabaseHas('v2_stat_user', ['user_id' => $user->id, 'u' => 300, 'd' => 500, 'server_rate' => 2]);
        $this->assertDatabaseHas('v2_stat_server', ['server_id' => $node->id, 'u' => 150, 'd' => 250]);
        $this->assertSame(0, $gateway->online($source->id)['online_connections']);
    }

    public function test_exhausted_quota_closes_session_and_prevents_new_admission(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['transfer_enable' => 300]);
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $this->assertFalse($gateway->record($id, 100, 200));
        $this->expectException(\DomainException::class);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.2');
    }

    public function test_fractional_rate_carries_sub_byte_remainders_between_flushes(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['rate' => 0.5]);
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $this->assertTrue($gateway->record($id, 1, 1));
        $this->assertTrue($gateway->record($id, 2, 2));
        $this->assertSame(1, $user->fresh()->u); $this->assertSame(1, $user->fresh()->d);
        $this->assertDatabaseHas('v2_stat_user', ['user_id' => $user->id, 'u' => 1, 'd' => 1, 'server_rate' => 0.5]);
    }

    public function test_user_quota_is_debited_at_rate_and_stops_existing_and_new_connections(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['rate' => 2]);
        $user->transfer_enable = 500; $user->saveQuietly();
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $this->assertFalse($gateway->record($id, 100, 200));
        $this->assertSame(600, $user->fresh()->u + $user->fresh()->d);
        $this->expectException(\DomainException::class);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
    }

    public function test_device_state_merges_gateway_and_managed_node_ips_without_double_counting(): void
    {
        [$source, $node, $user, $gateway] = $this->relay();
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        Redis::shouldReceive('hgetall')->with('user_devices:' . $user->id)->andReturn([
            '999:203.0.113.1' => time(), '999:203.0.113.2' => time(), '999:203.0.113.3' => time() - 600,
        ]);
        $devices = new DeviceStateService();
        $this->assertSame(2, $devices->getDeviceCount($user->id));
        $this->assertSame(['203.0.113.1', '203.0.113.2'], $devices->getUsersDevices([$user->id])[$user->id]);
    }

    public function test_expired_lease_is_not_resurrected_and_final_bytes_are_still_accounted(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['max_online' => 1]);
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        DB::table('v2_subscription_session')->where('id', $id)->update(['expires_at' => time() - 1]);
        $this->assertSame(0, $gateway->online($source->id)['online_ips']);
        $this->assertFalse($gateway->record($id, 12, 34));
        $this->assertSame(46, $source->fresh()->u + $source->fresh()->d);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.2');
    }

    public function test_gateway_rejects_banned_users_wrong_groups_and_personal_device_overflow(): void
    {
        [$source, $node, $user, $gateway] = $this->relay();
        $user->device_limit = 1; $user->saveQuietly();
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        try { $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.2'); $this->fail('Should enforce personal device limit'); }
        catch (\DomainException $e) { $this->assertSame('账号在线设备已达到上限', $e->getMessage()); }
        $user->banned = true; $user->saveQuietly();
        $this->assertFalse($gateway->record($id, 1, 2));
        $user->banned = false; $user->group_id = 999; $user->saveQuietly();
        $this->expectException(\DomainException::class);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
    }

    public function test_direct_mode_cannot_claim_online_or_quota_enforcement(): void
    {
        $preview = $this->preview();
        $this->import($preview, ['max_online' => 2])->assertUnprocessable();
        $this->import($preview, ['transfer_enable' => 1024])->assertUnprocessable();
        $this->assertDatabaseCount('v2_server_subscription', 0);
        $this->assertDatabaseCount('v2_server', 0);
    }

    public function test_connection_mode_blocks_same_user_and_ip_at_capacity(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['max_online' => 1, 'online_limit_mode' => 'connection']);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $this->expectException(\DomainException::class);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
    }

    public function test_user_mode_shares_a_slot_across_ips_but_blocks_other_users(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['max_online' => 1, 'online_limit_mode' => 'user']);
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.2');
        $other = $this->makeUser(); $other->group_id = $this->group->id; $other->saveQuietly();
        $this->expectException(\DomainException::class);
        $gateway->open($source->id, $node->id, $other->uuid, '203.0.113.1');
    }

    public function test_lowered_online_limit_applies_to_existing_sessions(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['max_online' => 2, 'online_limit_mode' => 'connection']);
        $one = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $two = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $source->update(['max_online' => 1]);
        $ids = [$one, $two]; sort($ids);
        $this->assertFalse($gateway->record($ids[1], 0, 0));
        $this->assertTrue($gateway->record($ids[0], 0, 0));
        $this->assertSame(1, $gateway->online($source->id)['online_connections']);
    }

    public function test_delete_disconnects_sessions_and_retains_final_accounting(): void
    {
        [$source, $node, $user, $gateway] = $this->relay();
        $id = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $this->postJson(action([SubscriptionController::class, 'deleteSubscription'], [], false), ['id' => $source->id])->assertOk();
        $this->assertFalse($gateway->record($id, 10, 20));
        $this->assertSame(30, $user->fresh()->u + $user->fresh()->d);
        $this->assertDatabaseHas('v2_stat_server', ['server_id' => $node->id, 'u' => 10, 'd' => 20]);
    }

    public function test_source_settings_are_admin_only(): void
    {
        $this->import($this->preview())->assertOk();
        $id = ServerSubscription::firstOrFail()->id;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $this->makeUser()->createToken('user')->plainTextToken);
        $this->getJson(action([SubscriptionController::class, 'subscriptions'], [], false))->assertForbidden();
        $this->getJson(action([SubscriptionController::class, 'gatewayConfig'], [], false))->assertForbidden();
        $this->postJson(action([SubscriptionController::class, 'saveSubscription'], [], false), ['id' => $id])->assertForbidden();
        $this->postJson(action([SubscriptionController::class, 'deleteSubscription'], [], false), ['id' => $id])->assertForbidden();
    }

    public function test_global_gateway_is_used_automatically_for_imports_and_existing_relay_exports(): void
    {
        [$source, $node, $user] = $this->relay();
        config()->set('subscription_gateway.host', 'shared.example.com');
        config()->set('subscription_gateway.port', 3443);
        Cache::put('subscription-gateway:' . $source->id, true, 30);
        $nodes = ServerService::getAvailableServers($user);
        $this->assertSame('shared.example.com', $nodes[0]['host']);
        $this->assertSame(3443, $nodes[0]['port']);
        $this->assertSame('shared.example.com', $nodes[0]['protocol_settings']['tls_settings']['server_name']);
        $this->assertSame('relay.example.com', $source->fresh()->gateway_host);
        $this->getJson(action([SubscriptionController::class, 'gatewayConfig'], [], false))->assertOk()
            ->assertJsonPath('data.host', 'shared.example.com')->assertJsonPath('data.port', 3443)->assertJsonPath('data.online', false);
        $this->import($this->preview(), ['delivery_mode' => 'relay'])->assertOk();
        $this->assertSame('shared.example.com', $source->fresh()->gateway_host);
        $this->assertSame(3443, $source->fresh()->gateway_port);
    }

    public function test_airports_can_share_endpoint_without_sharing_billing_or_online_limits(): void
    {
        [$source, $node, $user, $gateway] = $this->relay(['max_online' => 1, 'rate' => 2]);
        $other = ServerSubscription::create(['source' => str_repeat('b', 64), 'name' => 'Second airport',
            'group_ids' => [(string) $this->group->id], 'enabled' => true, 'delivery_mode' => 'relay',
            'max_online' => 1, 'online_limit_mode' => 'ip', 'gateway_host' => 'relay.example.com', 'gateway_port' => 2443]);
        $second = $node->replicate(); $second->subscription_id = $other->id; $second->external_key = str_repeat('c', 64);
        $second->rate = 3; $second->save();
        $this->postJson(action([SubscriptionController::class, 'saveSubscription'], [], false),
            ['id' => $other->id, 'gateway_host' => 'relay.example.com', 'gateway_port' => 2443])->assertOk();
        $one = $gateway->open($source->id, $node->id, $user->uuid, '203.0.113.1');
        $two = $gateway->open($other->id, $second->id, $user->uuid, '203.0.113.2');
        $this->assertTrue($gateway->record($one, 10, 20));
        $this->assertTrue($gateway->record($two, 100, 200));
        $this->assertSame(30, $source->fresh()->u + $source->fresh()->d);
        $this->assertSame(300, $other->fresh()->u + $other->fresh()->d);
        $this->assertSame(960, $user->fresh()->u + $user->fresh()->d);
        $this->assertSame(1, $gateway->online($source->id)['online_ips']);
        $this->assertSame(1, $gateway->online($other->id)['online_ips']);
        $this->expectException(\DomainException::class);
        $gateway->open($source->id, $second->id, $user->uuid, '203.0.113.1');
    }

    public function test_upgrade_groups_existing_nodes_without_losing_groups_or_credentials(): void
    {
        $this->import($this->preview())->assertOk();
        $nodes = Server::orderBy('id')->get();
        $group = new ServerGroup(); $group->name = 'Other'; $group->save();
        $nodes[1]->update(['group_ids' => [(string) $group->id]]);
        $migration = require database_path('migrations/2026_10_28_000003_add_subscription_management.php');
        $migration->down(); $migration->up();
        $source = ServerSubscription::firstOrFail();
        $this->assertSame('direct', $source->delivery_mode);
        $this->assertSame([(string) $this->group->id, (string) $group->id], $source->group_ids);
        $this->assertSame(2, $source->nodes()->count());
        $this->assertSame('upstream-secret', $nodes[0]->fresh()->external_password);
        $this->assertSame([(string) $group->id], $nodes[1]->fresh()->group_ids);
    }
}
