<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\SubscriptionController;
use App\Models\AdminAuditLog;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use App\Services\Subscription\SubscriptionFetcher;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'group_ids' => [$this->group->id], 'show' => true,
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
}
