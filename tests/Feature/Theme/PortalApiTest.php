<?php

namespace Tests\Feature\Theme;

use App\Models\StatUser;
use App\Models\Plan;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [];

    protected function beforeRefreshingDatabase(): void
    {
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.stores.redis', ['driver' => 'array']);
        DB::purge('sqlite');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $setting = Mockery::mock(Setting::class);
        $setting->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $this->settings[$key] ?? $default);
        $this->app->instance(Setting::class, $setting);
    }

    private function user(): User
    {
        return User::create([
            'email' => 'portal-test@example.com',
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => 'portal-test-uuid', 'token' => 'portal-subscription-test',
        ]);
    }

    public function test_user_info_exposes_only_the_current_users_linuxdo_binding_when_login_is_disabled(): void
    {
        config()->set('services.linuxdo.enabled', false);
        $user = $this->user();
        $user->update(['linuxdo_id' => '9007199254740993', 'linuxdo_username' => 'traveler']);
        User::create([
            'email' => 'other@example.com', 'password' => password_hash('other-password', PASSWORD_DEFAULT),
            'uuid' => 'other-uuid', 'token' => 'other-subscription-token',
            'linuxdo_id' => '456', 'linuxdo_username' => 'other-community-user',
        ]);
        $token = $user->createToken('account');
        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.linuxdo_id', '9007199254740993')
            ->assertJsonPath('data.linuxdo_username', 'traveler')
            ->assertJsonMissingPath('data.password')
            ->assertDontSee('other-community-user');
    }

    public function test_user_info_returns_null_for_an_unbound_linuxdo_account(): void
    {
        $token = $this->user()->createToken('account');
        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.linuxdo_id', null)
            ->assertJsonPath('data.linuxdo_username', null);
    }

    public function test_user_info_requires_authentication(): void
    {
        $this->getJson('/api/v1/user/info')->assertForbidden();
    }

    public function test_logout_revokes_only_the_current_session(): void
    {
        $user = $this->user();
        $current = $user->createToken('current');
        $other = $user->createToken('other');
        $this->withHeader('Authorization', 'Bearer ' . $current->plainTextToken)
            ->postJson('/api/v1/user/logout')->assertOk()->assertJson(['data' => true]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_daily_traffic_excludes_monthly_rollups_and_other_users(): void
    {
        $user = $this->user();
        $token = $user->createToken('traffic');
        foreach ([['user_id' => $user->id, 'record_type' => 'd'], ['user_id' => $user->id, 'record_type' => 'm'], ['user_id' => $user->id + 1, 'record_type' => 'd']] as $row) {
            StatUser::create($row + ['record_at' => now()->startOfMonth()->timestamp, 'server_rate' => 2, 'u' => 100, 'd' => 200]);
        }
        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/user/stat/getTrafficLog')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.u', 100)->assertJsonPath('data.0.d', 200);
    }

    public function test_user_config_exposes_configured_client_labels_and_links(): void
    {
        $this->settings = [
            'windows_version' => 'v2rayN',
            'windows_download_url' => 'https://downloads.example.test/windows.zip',
            'macos_version' => 'v2rayN for macOS',
            'macos_download_url' => 'https://downloads.example.test/macos.zip',
            'android_version' => 'v2rayNG',
            'android_download_url' => 'https://downloads.example.test/android.apk',
            'reset_traffic_method' => 2,
            'mail_password' => 'not-for-user-api',
        ];
        $token = $this->user()->createToken('config');
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/user/comm/config')->assertOk();
        foreach (array_diff_key($this->settings, ['mail_password' => true]) as $key => $value) {
            $response->assertJsonPath('data.' . $key, $value);
        }
        $response->assertJsonMissingPath('data.mail_password');
    }

    public function test_hidden_subscription_plan_renders_templates_and_preserves_raw_prices(): void
    {
        $this->settings['reset_traffic_method'] = '3';
        $content = '<p>{{transfer}} GB / {{speed}} Mbps / {{devices}} 台 / {{reset_method}}</p>';
        $plan = Plan::create([
            'name' => 'Hidden plan', 'group_id' => 1, 'show' => 0, 'sell' => 0, 'renew' => 1,
            'transfer_enable' => 200, 'device_limit' => null, 'speed_limit' => null,
            'reset_traffic_method' => null, 'prices' => ['monthly' => '29'], 'content' => $content,
        ]);
        $user = $this->user();
        $user->update(['plan_id' => $plan->id, 'transfer_enable' => 200 * 1073741824]);
        $token = $user->createToken('subscription');
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken, 'Content-Language' => 'zh-CN'])
            ->getJson('/api/v1/user/getSubscribe')->assertOk()
            ->assertJsonPath('data.plan.content', '<p>200 GB / 不限制 Mbps / 不限制 台 / 每年1月1日</p>')
            ->assertJsonPath('data.plan.prices.monthly', '29');
        $this->assertSame($content, $plan->fresh()->content);
    }

    public function test_catalog_template_supports_zero_limits_and_plan_reset_override(): void
    {
        $this->settings['reset_traffic_method'] = 3;
        Plan::create([
            'name' => 'Catalog plan', 'group_id' => 1, 'show' => 1, 'sell' => 1, 'renew' => 1,
            'transfer_enable' => 100, 'device_limit' => 0, 'speed_limit' => 0, 'capacity_limit' => null,
            'reset_traffic_method' => 0, 'prices' => ['monthly' => '10'],
            'content' => '{{transfer}}|{{speed}}|{{devices}}|{{reset_method}}',
        ]);
        $token = $this->user()->createToken('plans');
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken, 'Content-Language' => 'zh-CN'])
            ->getJson('/api/v1/user/plan/fetch')->assertOk()
            ->assertJsonPath('data.0.content', '100|0|0|每月1号');
    }

    public function test_catalog_returns_monthly_and_onetime_plans_without_exposing_unavailable_plans(): void
    {
        $base = [
            'group_id' => 1, 'show' => 1, 'sell' => 1, 'renew' => 1,
            'transfer_enable' => 60, 'capacity_limit' => null,
        ];
        $monthly = Plan::create(array_merge($base, ['name' => 'Monthly', 'sort' => 1, 'prices' => ['monthly' => '5.90']]));
        $onetime = Plan::create(array_merge($base, ['name' => 'Long-term traffic', 'sort' => 2, 'prices' => ['onetime' => '19.90']]));
        foreach ([['show' => 0], ['sell' => 0]] as $flags) {
            Plan::create(array_merge($base, ['name' => 'Unavailable', 'prices' => ['onetime' => '1']], $flags));
        }
        $soldOut = Plan::create(array_merge($base, ['name' => 'Full', 'prices' => ['onetime' => '1'], 'capacity_limit' => 1]));
        $user = $this->user();
        $user->update(['plan_id' => $soldOut->id, 'expired_at' => null]);
        $token = $user->createToken('catalog');
        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/user/plan/fetch')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $monthly->id)
            ->assertJsonPath('data.0.month_price', 590)
            ->assertJsonPath('data.1.id', $onetime->id)
            ->assertJsonPath('data.1.month_price', null)
            ->assertJsonPath('data.1.onetime_price', 1990);
    }

    public function test_default_zero_capacity_keeps_longterm_plans_visible_with_existing_subscribers(): void
    {
        $base = ['group_id' => 1, 'show' => 1, 'sell' => 1, 'renew' => 1, 'transfer_enable' => 60];
        $monthly = Plan::create($base + ['name' => '一杯咖啡', 'sort' => 1, 'prices' => ['monthly' => '5.90']]);
        $onetime = Plan::create($base + ['name' => '偶尔喝一口', 'sort' => 2, 'prices' => ['onetime' => '28.90']]);
        $this->assertSame(0, (int) $onetime->fresh()->capacity_limit);
        for ($i = 0; $i < 3; $i++) {
            User::create([
                'email' => "longterm-{$i}@example.test", 'password' => password_hash('test-only', PASSWORD_DEFAULT),
                'uuid' => "longterm-user-{$i}", 'token' => "longterm-sub-{$i}",
                'plan_id' => $onetime->id, 'expired_at' => null,
            ]);
        }
        $buyer = $this->user();
        $token = $buyer->createToken('zero-capacity');
        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/user/plan/fetch')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $monthly->id)
            ->assertJsonPath('data.1.id', $onetime->id)
            ->assertJsonPath('data.1.onetime_price', 2890)
            ->assertJsonPath('data.1.capacity_limit', null);
        $service = new \App\Services\PlanService($onetime);
        $this->assertTrue($service->isPlanAvailableForUser($onetime, $buyer));
        $service->validatePurchase($buyer, 'onetime_price');
    }
}
