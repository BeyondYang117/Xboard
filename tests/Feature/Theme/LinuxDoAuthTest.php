<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use App\Services\AuthService;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class LinuxDoAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [];
    private string $verifier;
    private const API = '/api/v1/passport/auth/linuxdo/';
    private const REDIRECT = 'https://panel.example.test/auth/linuxdo/callback';

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
        Cache::flush();
        config()->set('services.linuxdo', [
            'enabled' => true, 'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect_uri' => self::REDIRECT, 'min_trust_level' => 0,
        ]);
        $settings = Mockery::mock(Setting::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $this->settings[$key] ?? $default);
        $this->app->instance(Setting::class, $settings);
        $this->verifier = bin2hex(random_bytes(32));
        Http::preventStrayRequests();
        $this->provider();
    }

    private function provider(array $overrides = [], int $tokenStatus = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://connect.linux.do/oauth2/token' => Http::response(['access_token' => 'provider-secret'], $tokenStatus),
            'https://connect.linux.do/api/user' => Http::response(array_replace([
                'id' => 123, 'username' => 'traveler', 'name' => 'Traveler',
                'active' => true, 'silenced' => false, 'trust_level' => 2,
            ], $overrides)),
        ]);
    }

    private function user(array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => Str::random(12) . '@example.com', 'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
        ], $overrides));
    }

    private function start(): string
    {
        $response = $this->postJson(self::API . 'start', ['verifier' => $this->verifier])->assertOk();
        $url = $response->json('data.url');
        $this->assertStringStartsWith('https://connect.linux.do/oauth2/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame(self::REDIRECT, $params['redirect_uri']);
        $this->assertSame('code', $params['response_type']);
        $this->assertStringNotContainsString('test-secret', $url);
        return $params['state'];
    }

    private function oauthCallback(string $state, array $params = [])
    {
        return $this->get('/auth/linuxdo/callback?' . http_build_query(array_replace([
            'state' => $state, 'code' => 'test-code',
        ], $params)))->assertRedirect();
    }

    private function ticket(): string
    {
        $response = $this->oauthCallback($this->start());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://panel.example.test/#linuxdo?ticket=', $location);
        $this->assertStringNotContainsString('provider-secret', $location);
        parse_str(explode('?', $location, 2)[1], $params);
        return $params['ticket'];
    }

    private function payload(string $ticket, array $overrides = []): array
    {
        return array_replace(['ticket' => $ticket, 'verifier' => $this->verifier], $overrides);
    }

    public function test_disabled_or_incomplete_configuration_is_hidden_without_leaking_credentials(): void
    {
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.is_linuxdo', true)
            ->assertDontSee('test-secret')->assertDontSee('test-client');
        config()->set('services.linuxdo.client_secret', '');
        $this->getJson('/api/v1/guest/comm/config')->assertJsonPath('data.is_linuxdo', false);
        $this->postJson(self::API . 'start', ['verifier' => $this->verifier])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_invalid_callback_configuration_and_verifiers_are_rejected(): void
    {
        $this->postJson(self::API . 'start', ['verifier' => 'short'])->assertUnprocessable();
        foreach (['http://external.example/auth/linuxdo/callback', self::REDIRECT . '?redirect=evil', self::REDIRECT . '#fragment'] as $uri) {
            config()->set('services.linuxdo.redirect_uri', $uri);
            $this->postJson(self::API . 'start', ['verifier' => $this->verifier])->assertStatus(503);
        }
        Http::assertNothingSent();
    }

    public function test_state_is_random_single_use_and_required_before_any_provider_call(): void
    {
        $state = $this->start();
        $this->assertNotSame($state, $this->start());
        $this->oauthCallback(Str::random(64))->assertRedirectContains('error=');
        Http::assertNothingSent();
        $this->oauthCallback($state)->assertRedirectContains('ticket=');
        $this->oauthCallback($state)->assertRedirectContains('error=');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://connect.linux.do/oauth2/token'
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('test-client:test-secret'))
            && $request['grant_type'] === 'authorization_code' && $request['redirect_uri'] === self::REDIRECT);
    }

    public function test_denied_authorization_does_not_call_provider(): void
    {
        $this->oauthCallback($this->start(), ['code' => null, 'error' => 'access_denied'])->assertRedirectContains('error=');
        Http::assertNothingSent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_bound_account_logs_in_once_using_stable_id_and_refreshes_username(): void
    {
        $user = $this->user(['linuxdo_id' => '123', 'linuxdo_username' => 'old-name']);
        $this->settings['stop_register'] = 1;
        $ticket = $this->ticket();
        $this->postJson(self::API . 'exchange', $this->payload($ticket, ['verifier' => str_repeat('b', 64)]))->assertStatus(400);
        $response = $this->postJson(self::API . 'exchange', $this->payload($ticket))->assertOk();
        $this->assertSame($user->id, AuthService::findUserByBearerToken($response->json('data.auth_data'))->id);
        $this->assertSame('traveler', $user->fresh()->linuxdo_username);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->postJson(self::API . 'exchange', $this->payload($ticket))->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_new_identity_never_auto_links_by_email_and_requires_existing_account_password(): void
    {
        $user = $this->user(['email' => 'existing@example.com']);
        $this->provider(['email' => $user->email]);
        $ticket = $this->ticket();
        $this->postJson(self::API . 'exchange', $this->payload($ticket))->assertOk()
            ->assertJsonPath('data.requires_account', true)->assertJsonPath('data.username', 'traveler')
            ->assertJsonMissingPath('data.auth_data');
        $this->assertNull($user->fresh()->linuxdo_id);
        $this->settings['stop_register'] = 1;
        $body = $this->payload($ticket, ['action' => 'login', 'email' => $user->email, 'password' => 'wrong-password']);
        $this->postJson(self::API . 'complete', $body)->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson(self::API . 'complete', array_replace($body, ['password' => 'test-password']))->assertOk()
            ->assertJsonStructure(['data' => ['auth_data']]);
        $this->assertSame('123', $user->fresh()->linuxdo_id);
        $this->postJson(self::API . 'complete', $body)->assertStatus(400);
    }

    public function test_registration_preserves_closed_invite_email_and_captcha_rules_then_creates_one_bound_account(): void
    {
        $ticket = $this->ticket();
        $body = $this->payload($ticket, ['action' => 'register', 'email' => 'new@example.com', 'password' => 'new-password']);
        $this->settings['stop_register'] = 1;
        $this->postJson(self::API . 'complete', $body)->assertStatus(400);
        $this->settings = ['invite_force' => 1];
        $this->postJson(self::API . 'complete', $body)->assertStatus(422);
        $this->settings = ['email_verify' => 1];
        $this->postJson(self::API . 'complete', $body)->assertStatus(422);
        $this->settings = ['email_whitelist_enable' => 1, 'email_whitelist_suffix' => ['allowed.example']];
        $this->postJson(self::API . 'complete', $body)->assertStatus(400);
        $this->settings = ['captcha_enable' => 1, 'captcha_type' => 'turnstile'];
        $this->postJson(self::API . 'complete', $body)->assertStatus(400);
        $this->assertDatabaseCount('v2_user', 0);
        $this->settings = ['email_verify' => 1, 'register_limit_by_ip_enable' => 1];
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $body['email']), '123456', 300);
        $this->postJson(self::API . 'complete', $body + ['email_code' => '123456'])->assertOk();
        $this->assertDatabaseCount('v2_user', 1);
        $user = User::firstOrFail();
        $this->assertSame('123', $user->linuxdo_id);
        $this->assertTrue(password_verify('new-password', $user->password));
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $body['email'])));
        $this->assertSame(1, Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', '127.0.0.1')));
        $this->postJson(self::API . 'complete', $body)->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_forbidden_provider_accounts_and_bad_responses_never_issue_tickets(): void
    {
        foreach ([['active' => false], ['silenced' => true], ['active' => 'true'], ['silenced' => null],
            ['id' => 0], ['id' => ['invalid']], ['trust_level' => null], ['username' => ''], ['trust_level' => 1]] as $profile) {
            $this->provider($profile);
            config()->set('services.linuxdo.min_trust_level', 2);
            $this->oauthCallback($this->start())->assertRedirectContains('error=');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('v2_user', 0);
    }

    public function test_provider_http_errors_and_timeouts_return_to_theme_without_secrets(): void
    {
        $this->provider([], 401);
        $this->oauthCallback($this->start())->assertRedirectContains('error=')->assertDontSee('provider-secret');
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://connect.linux.do/*' => Http::failedConnection()]);
        $this->oauthCallback($this->start())->assertRedirectContains('error=');
    }

    public function test_banned_local_accounts_and_conflicting_links_are_rejected(): void
    {
        $user = $this->user(['linuxdo_id' => '123', 'banned' => true]);
        $ticket = $this->ticket();
        $this->postJson(self::API . 'exchange', $this->payload($ticket))->assertForbidden();
        $user->update(['linuxdo_id' => '456', 'banned' => false]);
        $this->postJson(self::API . 'complete', $this->payload($ticket, [
            'action' => 'login', 'email' => $user->email, 'password' => 'test-password',
        ]))->assertStatus(409);
        $this->assertSame('456', $user->fresh()->linuxdo_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_state_ticket_and_browser_proof_expire(): void
    {
        $state = $this->start();
        $this->travel(6)->minutes();
        $this->oauthCallback($state)->assertRedirectContains('error=');
        Http::assertNothingSent();
        $this->travelBack();
        $ticket = $this->ticket();
        $this->travel(6)->minutes();
        $this->postJson(self::API . 'exchange', $this->payload($ticket))->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
