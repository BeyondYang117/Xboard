<?php

namespace Tests\Feature\Telegram;

use App\Exceptions\ApiException;
use App\Exceptions\TelegramApiException;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Jobs\SendTelegramJob;
use App\Jobs\SendTelegramReminderJob;
use App\Jobs\SendTelegramTicketReplyJob;
use App\Models\Plan;
use App\Models\Plugin as PluginRecord;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\TelegramBindingService;
use App\Services\TelegramReminderService;
use App\Services\TelegramService;
use App\Services\TelegramTicketService;
use App\Services\TicketService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Plugin\Telegram\Plugin;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private Plugin $plugin;
    private array $settings = [
        'telegram_bot_token' => 'test-bot-token',
        'telegram_bot_enable' => true,
        'app_url' => 'https://example.test',
    ];

    protected function beforeRefreshingDatabase(): void
    {
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('cache.stores.redis', ['driver' => 'array']);
        DB::purge('sqlite');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        $setting = Mockery::mock(Setting::class);
        $setting->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $this->settings[$key] ?? $default);
        $this->app->instance(Setting::class, $setting);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'test_bot']])]);
        HookManager::reset();
        require_once base_path('plugins-core/Telegram/Plugin.php');
        $this->plugin = new Plugin('telegram');
        $this->plugin->boot();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(array $attributes = []): User
    {
        static $sequence = 0;
        $id = ++$sequence;
        return User::create($attributes + [
            'email' => "tg_{$id}@example.test", 'password' => 'unused',
            'uuid' => "tg-uuid-{$id}", 'token' => "tg-token-{$id}",
            'telegram_id' => 1001, 'plan_id' => 1, 'transfer_enable' => 100 * 1073741824,
            'u' => 10 * 1073741824, 'd' => 70 * 1073741824,
            'expired_at' => now()->addDays(3)->timestamp,
            'remind_expire' => true, 'remind_traffic' => true,
        ]);
    }

    private function message(string $command, array $attributes = []): object
    {
        return (object) ($attributes + [
            'command' => $command, 'text' => $command, 'args' => [], 'chat_id' => 1001,
            'sender_id' => 1001, 'is_private' => true, 'message_type' => 'message',
        ]);
    }

    private function clickButton(string $action): void
    {
        $this->plugin->handleMessage(false, [$this->message('', [
            'message_type' => 'callback_query', 'callback_data' => 'tg:' . $action,
        ])]);
    }

    private function assertSentText(string $text): void
    {
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], $text));
    }

    private function enableReminderPlugin(array $config = []): void
    {
        PluginRecord::updateOrCreate(['code' => 'telegram'], [
            'name' => 'Telegram', 'version' => '1.1.0', 'is_enabled' => true, 'config' => json_encode($config),
        ]);
    }

    private function linkCode(array $link): string
    {
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $query);
        return substr($query['start'], 5);
    }

    public function test_website_link_binds_only_after_private_bot_confirmation_and_cannot_be_reused(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user(['telegram_id' => null]);
        $token = $user->createToken('binding')->plainTextToken;
        $link = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/user/telegram/createBindingLink', ['user_id' => 999])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $code = $this->linkCode($link);
        $this->assertSame(now()->addMinutes(5)->timestamp, $link['expires_at']);
        $this->assertStringNotContainsString($user->token, $link['url']);
        $this->webhook(['message' => [
            'message_id' => 90, 'chat' => ['id' => 1001, 'type' => 'private'],
            'from' => ['id' => 1001], 'text' => '/start bind_' . $code,
        ]]);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSentText('确认绑定账号');
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request['text'] ?? '', $user->email));
        Http::assertSent(fn (ClientRequest $request) =>
            ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === 'tg:bind_confirm:' . $code);
        $this->clickButton('bind_confirm:' . $code);
        $this->assertSame(1001, $user->fresh()->telegram_id);
        app(TelegramBindingService::class)->unbindUser($user->id);
        $this->clickButton('bind_confirm:' . $code);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSentText('绑定链接已失效');
    }

    public function test_binding_links_expire_are_replaced_and_can_be_revoked_from_the_website(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user(['telegram_id' => null]);
        $service = app(TelegramBindingService::class);
        $old = $this->linkCode($service->createLink($user));
        $current = $this->linkCode($service->createLink($user));
        $this->clickButton('bind_confirm:' . $old);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSame($user->id, $service->previewLink($current)->id);
        $this->travel(301)->seconds();
        $this->clickButton('bind_confirm:' . $current);
        $this->assertNull($user->fresh()->telegram_id);
        $current = $this->linkCode($service->createLink($user));
        $token = $user->createToken('revoke')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/user/telegram/unbind')->assertOk()->assertJsonPath('data', true);
        $this->clickButton('bind_confirm:' . $current);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSentText('绑定链接已失效');
    }

    public function test_website_telegram_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/user/telegram/createBindingLink')->assertForbidden();
        $this->postJson('/api/v1/user/telegram/unbind')->assertForbidden();
    }

    public function test_website_unbind_only_changes_the_current_user_even_for_legacy_duplicate_bindings(): void
    {
        $user = $this->user();
        $other = $this->user();
        $token = $user->createToken('unbind')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/user/telegram/unbind', ['user_id' => $other->id, 'telegram_id' => 1001])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSame(1001, $other->fresh()->telegram_id);
        $this->settings['telegram_bot_enable'] = false;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/user/telegram/unbind')->assertOk();
    }

    public function test_binding_links_recheck_account_and_bot_state_at_confirmation(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user(['telegram_id' => null]);
        $service = app(TelegramBindingService::class);
        $code = $this->linkCode($service->createLink($user));
        $user->update(['banned' => true]);
        $this->clickButton('bind_confirm:' . $code);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSentText('账号不可用');
        $user->update(['banned' => false, 'telegram_id' => 2002]);
        $this->clickButton('bind_confirm:' . $code);
        $this->assertSame(2002, $user->fresh()->telegram_id);
        $user->update(['telegram_id' => null]);
        $this->settings['telegram_bot_enable'] = false;
        $this->clickButton('bind_confirm:' . $code);
        $this->assertNull($user->fresh()->telegram_id);
        $this->settings['telegram_bot_enable'] = true;
        PluginRecord::where('code', 'telegram')->update(['is_enabled' => false]);
        $this->clickButton('bind_confirm:' . $code);
        $this->assertNull($user->fresh()->telegram_id);
        PluginRecord::where('code', 'telegram')->update(['is_enabled' => true]);
        $this->settings['telegram_bot_token'] = 'rotated-bot';
        $this->clickButton('bind_confirm:' . $code);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSentText('绑定链接已失效');
    }

    public function test_link_confirmation_cannot_replace_an_existing_telegram_binding(): void
    {
        $this->enableReminderPlugin();
        $bound = $this->user();
        $target = $this->user(['telegram_id' => null]);
        $code = $this->linkCode(app(TelegramBindingService::class)->createLink($target));
        $this->clickButton('bind_confirm:' . $code);
        $this->assertNull($target->fresh()->telegram_id);
        $this->assertSame(1001, $bound->fresh()->telegram_id);
        $this->assertSentText('当前 Telegram 已绑定账号');
    }

    public function test_link_binding_is_private_and_rejects_malformed_payloads(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user(['telegram_id' => null]);
        $code = $this->linkCode(app(TelegramBindingService::class)->createLink($user));
        $this->plugin->handleMessage(false, [$this->message('/start', [
            'args' => ['bind_' . $code], 'is_private' => false,
        ])]);
        $this->assertSentText('请在私聊中使用此命令');
        $this->plugin->handleMessage(false, [$this->message('/start', ['args' => ['bind_bad']])]);
        $this->assertSentText('绑定链接已失效');
        $this->assertNull($user->fresh()->telegram_id);
    }

    public function test_link_issuance_rejects_disabled_bot_banned_and_already_bound_accounts(): void
    {
        $user = $this->user(['telegram_id' => null]);
        $token = $user->createToken('issue')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/user/telegram/createBindingLink')->assertStatus(400);
        $this->enableReminderPlugin();
        $user->update(['banned' => true]);
        $this->postJson('/api/v1/user/telegram/createBindingLink')->assertStatus(400);
        $user->update(['banned' => false, 'telegram_id' => 1001]);
        $this->postJson('/api/v1/user/telegram/createBindingLink')->assertStatus(400);
    }

    public function test_menu_and_account_callback_show_real_account_data_without_markdown_corruption(): void
    {
        $plan = Plan::create(['name' => 'Pro_*[]', 'group_id' => 1, 'transfer_enable' => 100, 'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        $user = $this->user(['plan_id' => $plan->id, 'balance' => 1234, 'next_reset_at' => now()->addDay()->timestamp]);
        $this->plugin->handleStartCommand($this->message('/start'));
        Http::assertSent(fn (ClientRequest $request) => $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'tg:account');
        $this->clickButton('account');
        $this->assertSentText($user->email);
        $this->assertSentText('Pro_*[]');
        $this->assertSentText('20.00 / 100.00 GB');
        $this->assertSentText('12.34 元');
        $this->assertSentText('下次重置：');
        Http::assertSent(fn (ClientRequest $request) => !array_key_exists('parse_mode', $request->data()) && $request->method() === 'POST');
    }

    public function test_group_start_does_not_disclose_an_account(): void
    {
        $this->user();
        $this->plugin->handleStartCommand($this->message('/start', ['is_private' => false]));
        Http::assertSentCount(1);
        $this->assertSentText('请在私聊中使用此命令');
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request['text'], '@example.test'));
    }

    public function test_overused_traffic_never_displays_negative_remaining_bytes(): void
    {
        $this->user(['d' => 110 * 1073741824]);
        $this->plugin->handleTrafficCommand($this->message('/traffic'));
        $this->assertSentText('剩余流量：0.00 GB');
        $this->assertSentText('使用率：100.00%');
    }

    public function test_a_telegram_account_cannot_bind_a_second_site_account(): void
    {
        $bound = $this->user();
        $other = $this->user(['telegram_id' => null]);
        $this->plugin->handleMessage(false, [$this->message('/bind', ['args' => ['https://example.test/api/v1/client/subscribe?token=' . $other->token]])]);
        $this->assertSame($bound->id, User::where('telegram_id', 1001)->sole()->id);
        $this->assertNull($other->fresh()->telegram_id);
        $this->assertSentText('当前 Telegram 已绑定账号');
    }

    public function test_an_already_bound_site_account_cannot_be_taken_over(): void
    {
        $user = $this->user(['telegram_id' => 2002]);
        try {
            app(TelegramBindingService::class)->bind(1001, $user->token);
            $this->fail('Expected binding to be rejected');
        } catch (ApiException $e) {
            $this->assertStringContainsString('已经绑定', $e->getMessage());
        }
        $this->assertSame(2002, $user->fresh()->telegram_id);
    }

    public function test_binding_accepts_a_valid_subscription_but_rejects_array_tokens(): void
    {
        $user = $this->user(['telegram_id' => null]);
        $this->plugin->handleMessage(false, [$this->message('/bind', ['args' => ['https://example.test/subscribe?token[]=bad']])]);
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertSentText('订阅地址无效');
        $this->plugin->handleMessage(false, [$this->message('/bind', ['args' => ['https://example.test/subscribe?token=' . $user->token]])]);
        $this->assertSame(1001, $user->fresh()->telegram_id);
    }

    public function test_unbind_requires_confirmation_and_cleans_up_legacy_duplicate_bindings(): void
    {
        $first = $this->user();
        $second = $this->user();
        $other = $this->user(['telegram_id' => 2002]);
        $this->plugin->handleUnbindCommand($this->message('/unbind'));
        $this->assertSame(2, User::where('telegram_id', 1001)->count());
        $this->clickButton('unbind_confirm');
        $this->assertNull($first->fresh()->telegram_id);
        $this->assertNull($second->fresh()->telegram_id);
        $this->assertSame(2002, $other->fresh()->telegram_id);
    }

    public function test_duplicate_bindings_do_not_show_an_arbitrary_accounts_private_data(): void
    {
        $this->user();
        $this->user();
        $this->clickButton('account');
        $this->assertSentText('历史重复绑定');
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request['text'], '@example.test'));
    }

    public function test_reminder_buttons_set_explicit_preferences_and_are_idempotent(): void
    {
        $user = $this->user();
        $this->clickButton('traffic_off');
        $this->clickButton('traffic_off');
        $this->assertFalse($user->fresh()->remind_traffic);
        $this->assertTrue($user->fresh()->remind_expire);
        $this->clickButton('expire_off');
        $this->clickButton('traffic_on');
        $this->assertFalse($user->fresh()->remind_expire);
        $this->assertTrue($user->fresh()->remind_traffic);
    }

    public function test_regular_users_cannot_reply_to_any_ticket_as_staff(): void
    {
        $this->user();
        $service = Mockery::mock(TicketService::class);
        $service->shouldNotReceive('replyByAdmin');
        $this->app->instance(TicketService::class, $service);
        $msg = $this->message('', ['text' => 'forged reply', 'reply_from_bot' => true]);
        $this->plugin->handleTicketReply($msg, ['', '工单ID:', '123']);
        $this->assertSentText('仅限管理员或客服');
    }

    public function test_staff_can_reply_only_to_open_tickets_from_bot_notifications(): void
    {
        $staff = $this->user(['is_staff' => true]);
        $owner = $this->user(['telegram_id' => null]);
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'Help', 'level' => 1]);
        $service = Mockery::mock(TicketService::class);
        $service->shouldReceive('replyByAdmin')->once()->with($ticket->id, 'answer', $staff->id);
        $this->app->instance(TicketService::class, $service);
        $msg = $this->message('', ['text' => 'answer', 'reply_from_bot' => false]);
        $this->plugin->handleTicketReply($msg, ['', '工单ID:', (string) $ticket->id]);
        $msg->reply_from_bot = true;
        $this->plugin->handleTicketReply($msg, ['', '工单ID:', (string) $ticket->id]);
        $ticket->update(['status' => Ticket::STATUS_CLOSED]);
        $this->plugin->handleTicketReply($msg, ['', '工单ID:', (string) $ticket->id]);
        $this->assertSentText('工单已关闭');
    }

    public function test_webhook_acknowledges_callbacks_and_dispatches_the_account_action(): void
    {
        $this->user();
        $this->webhook(['callback_query' => [
            'id' => 'callback-1', 'data' => 'tg:traffic', 'from' => ['id' => 1001],
            'message' => ['message_id' => 12, 'chat' => ['id' => 1001, 'type' => 'private']],
        ]]);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/answerCallbackQuery') && $request['callback_query_id'] === 'callback-1');
        $this->assertSentText('流量使用情况');
    }

    public function test_webhook_rejects_callback_identity_mismatches(): void
    {
        $this->user();
        $this->webhook(['callback_query' => [
            'id' => 'forged-callback', 'data' => 'tg:account', 'from' => ['id' => 2002],
            'message' => ['message_id' => 12, 'chat' => ['id' => 1001, 'type' => 'private']],
        ]]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_http_webhook_loads_the_enabled_core_plugin_and_displays_the_menu(): void
    {
        $this->enableReminderPlugin();
        $this->user();
        HookManager::reset();
        $this->app->forgetInstance(PluginManager::class);
        $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5('test-bot-token'), [
            'message' => [
                'message_id' => 10, 'chat' => ['id' => 1001, 'type' => 'private'],
                'from' => ['id' => 1001], 'text' => '/start',
            ],
        ])->assertSuccessful();
        $this->assertSentText('已绑定账号');
        Http::assertSent(fn (ClientRequest $request) => isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_webhook_accepts_whitespace_and_commands_sent_as_replies(): void
    {
        $user = $this->user(['telegram_id' => null]);
        $this->webhook(['message' => [
            'message_id' => 10, 'chat' => ['id' => 1001, 'type' => 'private'], 'from' => ['id' => 1001],
            'text' => "/bind   https://example.test/subscribe?token={$user->token}",
            'reply_to_message' => ['text' => 'old welcome', 'from' => ['is_bot' => true]],
        ]]);
        $this->assertSame(1001, $user->fresh()->telegram_id);
    }

    private function webhook(array $update): void
    {
        $request = Request::create('/api/v1/guest/telegram/webhook?access_token=' . md5('test-bot-token'), 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($update));
        app(TelegramController::class)->webhook($request);
    }

    public function test_reminders_obey_thresholds_stages_and_global_and_user_switches(): void
    {
        $user = $this->user();
        $service = app(TelegramReminderService::class);
        $this->assertSame(['expire', 'traffic'], array_keys($service->remindersFor($user, [])));
        $this->assertSame([], $service->remindersFor($user, ['enable_expire_reminder' => 'false', 'enable_traffic_reminder' => false]));
        $this->assertSame([], $service->remindersFor($user, ['expire_reminder_days' => '7,1', 'traffic_reminder_percent' => 90]));
        $user->remind_expire = false;
        $user->remind_traffic = false;
        $this->assertSame([], $service->remindersFor($user, []));
    }

    public function test_ineligible_accounts_never_receive_reminders(): void
    {
        $service = app(TelegramReminderService::class);
        foreach ([['telegram_id' => null], ['banned' => true], ['plan_id' => null], ['expired_at' => now()->timestamp]] as $attributes) {
            $this->assertSame([], $service->remindersFor($this->user($attributes), []));
        }
        $user = $this->user(['expired_at' => null, 'u' => 0, 'd' => 0]);
        $this->assertSame([], $service->remindersFor($user, []));
    }

    public function test_successful_reminders_are_deduplicated_and_traffic_can_remind_again_after_a_day(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user();
        $service = app(TelegramReminderService::class);
        $reminders = $service->remindersFor($user, []);
        foreach ($reminders as $kind => $reminder) {
            $service->send($user->id, $kind, $reminder['key']);
            $service->send($user->id, $kind, $reminder['key']);
        }
        Http::assertSentCount(2);
        $this->travel(24)->hours();
        $service->send($user->id, 'traffic', $reminders['traffic']['key']);
        Http::assertSentCount(3);
    }

    public function test_failed_deliveries_do_not_consume_the_reminder(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user();
        $service = app(TelegramReminderService::class);
        $key = $service->remindersFor($user, [])['traffic']['key'];
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 503], 503)->push(['ok' => true]);
        try {
            $service->send($user->id, 'traffic', $key);
            $this->fail('Expected a Telegram failure');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $this->assertFalse(Cache::has($key));
        $service->send($user->id, 'traffic', $key);
        $this->assertTrue(Cache::has($key));
        Http::assertSentCount(2);
    }

    public function test_queued_reminders_recheck_unbinding_renewals_resets_and_preferences(): void
    {
        $this->enableReminderPlugin();
        $service = app(TelegramReminderService::class);
        foreach ([['telegram_id' => null], ['telegram_id' => 2002], ['remind_traffic' => false], ['last_reset_at' => now()->timestamp, 'd' => 0]] as $index => $changes) {
            $user = $this->user(['telegram_id' => 10000 + $index]);
            $key = $service->remindersFor($user, [])['traffic']['key'];
            $user->update($changes);
            $service->send($user->id, 'traffic', $key);
        }
        $user = $this->user();
        $key = $service->remindersFor($user, [])['expire']['key'];
        $user->update(['expired_at' => now()->addYear()->timestamp]);
        $service->send($user->id, 'expire', $key);
        Http::assertNothingSent();
    }

    public function test_disabled_plugin_drops_previously_queued_reminders(): void
    {
        $this->enableReminderPlugin();
        $user = $this->user();
        $service = app(TelegramReminderService::class);
        $key = $service->remindersFor($user, [])['traffic']['key'];
        PluginRecord::where('code', 'telegram')->update(['is_enabled' => false]);
        $service->send($user->id, 'traffic', $key);
        Http::assertNothingSent();
    }

    public function test_scheduled_scan_enqueues_due_reminders_on_the_telegram_queue(): void
    {
        Queue::fake();
        $this->user();
        $this->user(['telegram_id' => null]);
        app(TelegramReminderService::class)->dispatch([]);
        Queue::assertPushed(SendTelegramReminderJob::class, 2);
        Queue::assertPushedOn('send_telegram', SendTelegramReminderJob::class);
        $this->settings['telegram_bot_enable'] = false;
        app(TelegramReminderService::class)->dispatch([]);
        Queue::assertPushed(SendTelegramReminderJob::class, 2);
    }

    public function test_transport_posts_literal_text_and_handles_rate_limits_without_http_retries(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 45]], 429);
        try {
            app(TelegramService::class)->sendMessage(1001, 'token_under_score');
            $this->fail('Expected rate limiting');
        } catch (TelegramApiException $e) {
            $this->assertSame(45, $e->retryAfter);
            $this->assertTrue($e->isRetryable());
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST' && $request['text'] === 'token_under_score');
    }

    public function test_plain_text_omits_parse_mode_and_formatted_messages_keep_it(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (array_key_exists('parse_mode', $request->data()) && !$request['parse_mode']) {
                return Http::response([
                    'ok' => false, 'error_code' => 400,
                    'description' => 'Bad Request: unsupported parse_mode',
                ], 400);
            }
            return Http::response(['ok' => true, 'result' => []]);
        });

        $service = app(TelegramService::class);
        $service->sendMessage(1001, 'literal_under_score');
        $service->sendMessage(1001, 'formatted_under_score', 'markdown');

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request) => $request['text'] === 'literal_under_score'
            && !array_key_exists('parse_mode', $request->data()));
        Http::assertSent(fn (ClientRequest $request) => ($request->data()['parse_mode'] ?? null) === 'markdown'
            && $request['text'] === 'formatted\\_under\\_score');
    }

    public function test_queue_jobs_respect_rate_limits_and_fail_permanent_errors(): void
    {
        $service = Mockery::mock(TelegramService::class);
        $service->shouldReceive('sendMessage')->once()->andThrow(new TelegramApiException('limited', 429, 45));
        $this->app->instance(TelegramService::class, $service);
        $job = (new SendTelegramJob(1001, 'hello'))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(45);
        $service = Mockery::mock(TelegramService::class);
        $service->shouldReceive('sendMessage')->once()->andThrow(new TelegramApiException('blocked', 403));
        $this->app->instance(TelegramService::class, $service);
        $job = (new SendTelegramJob(1001, 'hello'))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith(TelegramApiException::class);
        $job->assertNotReleased();
    }

    public function test_registered_commands_have_valid_names_and_include_the_new_menu(): void
    {
        $commands = $this->plugin->addBotCommands([]);
        $this->assertContains('account', array_column($commands, 'command'));
        $this->assertContains('reminders', array_column($commands, 'command'));
        foreach ($commands as $command) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $command['command']);
        }
    }

    private function ticket(User $owner, array $attributes = []): Ticket
    {
        $ticket = Ticket::create($attributes + [
            'user_id' => $owner->id, 'subject' => '网络连接问题', 'level' => 1,
            'reply_status' => Ticket::REPLY_STATUS_WAITING, 'last_reply_user_id' => $owner->id,
        ]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'message' => '原始问题']);
        return $ticket;
    }

    private function input(string $text, array $attributes = []): void
    {
        $this->plugin->handleMessage(false, [$this->message($text, $attributes + ['text' => $text])]);
    }

    public function test_user_creates_a_ticket_in_two_steps_and_notifies_staff(): void
    {
        Queue::fake();
        $owner = $this->user();
        $this->user(['telegram_id' => 2002, 'is_staff' => true]);
        $this->clickButton('newticket');
        $this->input('无法连接');
        $this->assertDatabaseCount('v2_ticket', 0);
        $this->input('今天无法连接，请帮忙检查');
        $ticket = Ticket::sole();
        $this->assertSame($owner->id, $ticket->user_id);
        $this->assertSame('无法连接', $ticket->subject);
        $this->assertSame(1, $ticket->level);
        $this->assertSame('今天无法连接，请帮忙检查', $ticket->messages()->sole()->message);
        $this->assertFalse(app(TelegramTicketService::class)->hasDraft(1001));
        $this->assertSentText('已提交');
        Queue::assertPushedOn('send_telegram', SendTelegramJob::class);
    }

    public function test_new_ticket_respects_the_single_open_ticket_rule(): void
    {
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $this->clickButton('newticket');
        $this->assertDatabaseCount('v2_ticket', 1);
        $this->assertFalse(app(TelegramTicketService::class)->hasDraft(1001));
        $this->assertSentText("未关闭的工单 #{$ticket->id}");
    }

    public function test_creation_rechecks_for_tickets_created_on_the_website_during_the_draft(): void
    {
        $owner = $this->user();
        $this->clickButton('newticket');
        $this->input('draft title');
        $this->ticket($owner);
        $this->input('draft body');
        $this->assertDatabaseCount('v2_ticket', 1);
        $this->assertDatabaseCount('v2_ticket_message', 1);
        $this->assertSame(1, DB::transactionLevel());
    }

    public function test_ticket_input_can_be_cancelled_expired_or_left_via_the_menu(): void
    {
        $this->user();
        $service = app(TelegramTicketService::class);
        $this->clickButton('newticket');
        $this->input('/cancel');
        $this->assertFalse($service->hasDraft(1001));
        $this->input('this should not be a title');
        $this->assertDatabaseCount('v2_ticket', 0);
        $this->clickButton('newticket');
        $this->clickButton('menu');
        $this->assertFalse($service->hasDraft(1001));
        $this->clickButton('newticket');
        $this->travel(16)->minutes();
        $this->input('expired title');
        $this->assertFalse($service->hasDraft(1001));
        $this->assertDatabaseCount('v2_ticket', 0);
    }

    public function test_ticket_drafts_are_not_transferred_to_a_different_site_account(): void
    {
        $first = $this->user();
        $this->clickButton('newticket');
        $this->input('private first account title');
        $first->update(['telegram_id' => null]);
        $this->user();
        $this->input('must not submit on behalf of the first account');
        $this->assertDatabaseCount('v2_ticket', 0);
        $this->assertFalse(app(TelegramTicketService::class)->hasDraft(1001));
        $this->assertSentText('输入步骤已结束');
    }

    public function test_user_can_reply_using_a_button_and_bot_message(): void
    {
        Queue::fake();
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $this->clickButton("ticket_reply:{$ticket->id}");
        $this->input('button response');
        $this->input('direct response', [
            'message_type' => 'reply_message', 'reply_from_bot' => true,
            'reply_text' => "📬 客服回复 #{$ticket->id}\nsubject",
        ]);
        $this->assertSame(3, $ticket->messages()->count());
        $this->assertSame($owner->id, $ticket->fresh()->last_reply_user_id);
        $this->assertSame(Ticket::REPLY_STATUS_WAITING, $ticket->fresh()->reply_status);
        $this->assertSame('direct response', $ticket->messages()->orderByDesc('id')->first()->message);
    }

    public function test_user_cannot_view_reply_or_close_another_users_ticket(): void
    {
        $this->user();
        $other = $this->user(['telegram_id' => 2002]);
        $ticket = $this->ticket($other, ['subject' => 'other-user-private-title']);
        foreach (["ticket:{$ticket->id}:1", "ticket_reply:{$ticket->id}", "ticket_close:{$ticket->id}", "ticket_close_confirm:{$ticket->id}"] as $action) {
            $this->clickButton($action);
        }
        $this->input('forged response', [
            'message_type' => 'reply_message', 'reply_from_bot' => true,
            'reply_text' => "📬 客服回复 #{$ticket->id}",
        ]);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(Ticket::STATUS_OPENING, $ticket->fresh()->status);
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request['text'], 'other-user-private-title'));
    }

    public function test_user_reply_respects_wait_for_staff_setting_and_closed_status(): void
    {
        Queue::fake();
        $this->settings['ticket_must_wait_reply'] = true;
        $owner = $this->user();
        $staff = $this->user(['telegram_id' => 2002, 'is_staff' => true]);
        $ticket = $this->ticket($owner);
        $this->clickButton("ticket_reply:{$ticket->id}");
        $this->input('must wait');
        $this->assertSame(1, $ticket->messages()->count());
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $staff->id, 'message' => 'staff response']);
        $this->input('allowed response');
        $this->assertSame(3, $ticket->messages()->count());
        $ticket->update(['status' => Ticket::STATUS_CLOSED]);
        $this->input('closed response', [
            'message_type' => 'reply_message', 'reply_from_bot' => true,
            'reply_text' => "📨 我的工单 #{$ticket->id}",
        ]);
        $this->assertSame(3, $ticket->messages()->count());
    }

    public function test_close_requires_confirmation_and_keeps_history_readable(): void
    {
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $this->clickButton("ticket_close:{$ticket->id}");
        $this->assertSame(Ticket::STATUS_OPENING, $ticket->fresh()->status);
        $this->clickButton("ticket_close_confirm:{$ticket->id}");
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->clickButton("ticket:{$ticket->id}:1");
        $this->assertSentText('原始问题');
        $this->assertSentText('已关闭');
    }

    public function test_ticket_lists_and_history_are_paginated_and_messages_fit_telegram_limits(): void
    {
        $owner = $this->user();
        for ($i = 0; $i < 7; $i++) {
            $ticket = $this->ticket($owner, ['status' => Ticket::STATUS_CLOSED]);
        }
        for ($i = 0; $i < 4; $i++) {
            TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'message' => str_repeat('长内容', 5000)]);
        }
        $this->clickButton('tickets');
        $this->clickButton('tickets:2');
        $this->clickButton("ticket:{$ticket->id}:1");
        $this->clickButton("ticket:{$ticket->id}:2");
        Http::assertSent(fn (ClientRequest $request) => str_contains(json_encode($request['reply_markup']), 'tg:tickets:2'));
        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $this->assertLessThanOrEqual(4096, mb_strlen($request['text']));
            }
        }
        $this->assertSentText('完整内容请登录网站查看');
    }

    public function test_long_or_empty_ticket_inputs_do_not_create_partial_records(): void
    {
        $this->user();
        $this->clickButton('newticket');
        $this->input(str_repeat('题', 121));
        $this->assertSentText('不能超过 120 字');
        $this->input('valid title');
        $this->input('   ');
        $this->input(str_repeat('文', 3501));
        $this->assertDatabaseCount('v2_ticket', 0);
        $this->assertDatabaseCount('v2_ticket_message', 0);
        $this->assertTrue(app(TelegramTicketService::class)->hasDraft(1001));
    }

    public function test_ticket_switch_and_banned_or_unbound_users_cannot_create_tickets(): void
    {
        $user = $this->user();
        $this->plugin->setConfig(['enable_user_tickets' => false]);
        $this->clickButton('newticket');
        $this->assertFalse(app(TelegramTicketService::class)->hasDraft(1001));
        $this->plugin->setConfig([]);
        $user->update(['banned' => true]);
        $this->clickButton('newticket');
        $user->update(['banned' => false, 'telegram_id' => null]);
        $this->clickButton('newticket');
        $this->assertDatabaseCount('v2_ticket', 0);
    }

    public function test_staff_replies_from_website_or_bot_queue_notifications_after_commit(): void
    {
        Queue::fake();
        $owner = $this->user();
        $staff = $this->user(['telegram_id' => 2002, 'is_staff' => true]);
        $ticket = $this->ticket($owner);
        app(TicketService::class)->replyByAdmin($ticket->id, 'website staff response', $staff->id);
        $message = $ticket->messages()->orderByDesc('id')->first();
        Queue::assertPushed(SendTelegramTicketReplyJob::class, fn ($job) =>
            $job->messageId === $message->id && $job->userId === $owner->id && $job->telegramId === 1001 && $job->afterCommit
        );
        $this->plugin->setConfig(['enable_user_ticket_notify' => false]);
        HookManager::call('ticket.reply.admin.after', [$ticket, $message]);
        Queue::assertPushed(SendTelegramTicketReplyJob::class, 1);
    }

    public function test_queued_staff_reply_notifies_current_owner_once_and_preserves_plain_text(): void
    {
        $this->enableReminderPlugin();
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 9999, 'message' => 'answer_*[]']);
        $service = app(TelegramTicketService::class);
        $service->notifyReply($message->id, $owner->id, 1001);
        $service->notifyReply($message->id, $owner->id, 1001);
        Http::assertSentCount(1);
        $this->assertSentText('answer_*[]');
        Http::assertSent(fn (ClientRequest $request) => $request['chat_id'] === 1001 && !array_key_exists('parse_mode', $request->data()));
    }

    public function test_queued_staff_reply_is_dropped_after_binding_config_or_owner_changes(): void
    {
        $this->enableReminderPlugin();
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 9999, 'message' => 'private answer']);
        $service = app(TelegramTicketService::class);
        foreach ([['telegram_id' => null], ['telegram_id' => 2002], ['banned' => true]] as $changes) {
            $owner->update($changes);
            $service->notifyReply($message->id, $owner->id, 1001);
            $owner->update(['telegram_id' => 1001, 'banned' => false]);
        }
        $other = $this->user();
        $service->notifyReply($message->id, $owner->id, 1001);
        $other->update(['telegram_id' => 2002]);
        foreach ([['enable_user_tickets' => false], ['enable_user_ticket_notify' => false]] as $config) {
            $this->enableReminderPlugin($config);
            $service->notifyReply($message->id, $owner->id, 1001);
        }
        $this->enableReminderPlugin();
        $ticket->update(['user_id' => $other->id]);
        $service->notifyReply($message->id, $owner->id, 1001);
        Http::assertNothingSent();
    }

    public function test_duplicate_webhook_updates_do_not_repeat_ticket_steps_or_replies(): void
    {
        $owner = $this->user();
        $this->clickButton('newticket');
        $update = ['update_id' => 100, 'message' => [
            'message_id' => 10, 'chat' => ['id' => 1001, 'type' => 'private'], 'from' => ['id' => 1001],
            'text' => 'only the title',
        ]];
        $this->webhook($update);
        $this->webhook($update);
        $this->assertDatabaseCount('v2_ticket', 0);
        $update['update_id'] = 101;
        $update['message']['message_id'] = 11;
        $update['message']['text'] = 'only one body';
        $this->webhook($update);
        $this->webhook($update);
        $this->assertDatabaseCount('v2_ticket', 1);
        $ticket = Ticket::sole();
        $update['update_id'] = 102;
        $update['message']['text'] = 'only one reply';
        $update['message']['reply_to_message'] = [
            'text' => "📬 客服回复 #{$ticket->id}", 'from' => ['is_bot' => true],
        ];
        $this->webhook($update);
        $this->webhook($update);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame($owner->id, $ticket->fresh()->last_reply_user_id);
    }

    public function test_website_user_reply_uses_the_same_owner_and_wait_rules(): void
    {
        Queue::fake();
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $token = $owner->createToken('ticket-test')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/user/ticket/reply', [
            'id' => $ticket->id, 'message' => 'website user response',
        ])->assertOk()->assertJsonPath('data', true);
        $this->assertSame(2, $ticket->messages()->count());
        $this->settings['ticket_must_wait_reply'] = true;
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/user/ticket/reply', [
            'id' => $ticket->id, 'message' => 'must wait for staff',
        ])->assertStatus(400);
        $this->assertSame(2, $ticket->messages()->count());
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/user/ticket/close', [
            'id' => $ticket->id,
        ])->assertOk()->assertJsonPath('data', true);
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
    }

    public function test_actual_staff_bot_reply_queues_a_notification_for_the_ticket_owner(): void
    {
        Queue::fake();
        $owner = $this->user();
        $staff = $this->user(['telegram_id' => 2002, 'is_staff' => true]);
        $ticket = $this->ticket($owner);
        $this->input('bot staff response', [
            'chat_id' => 2002, 'sender_id' => 2002, 'message_type' => 'reply_message',
            'reply_from_bot' => true, 'reply_text' => "📮 工单提醒 #{$ticket->id}\nuser details",
        ]);
        $message = $ticket->messages()->orderByDesc('id')->first();
        $this->assertSame($staff->id, $message->user_id);
        $this->assertSame('bot staff response', $message->message);
        Queue::assertPushed(SendTelegramTicketReplyJob::class, fn ($job) =>
            $job->messageId === $message->id && $job->userId === $owner->id && $job->telegramId === 1001
        );
    }

    public function test_ticket_reply_notification_failures_can_retry_without_consuming_deduplication(): void
    {
        $this->enableReminderPlugin();
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 9999, 'message' => 'retry answer']);
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 503], 503)->push(['ok' => true]);
        $service = app(TelegramTicketService::class);
        try {
            $service->notifyReply($message->id, $owner->id, 1001);
            $this->fail('Expected Telegram failure');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $service->notifyReply($message->id, $owner->id, 1001);
        $service->notifyReply($message->id, $owner->id, 1001);
        Http::assertSentCount(2);
    }

    public function test_user_ticket_text_containing_staff_markers_is_still_an_owner_reply(): void
    {
        Queue::fake();
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $this->input('normal owner response', [
            'message_type' => 'reply_message', 'reply_from_bot' => true,
            'reply_text' => "📬 客服回复 #{$ticket->id}\n工单ID: 99999",
        ]);
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_website_ticket_creation_shares_the_single_open_ticket_transaction(): void
    {
        Queue::fake();
        $owner = $this->user();
        $token = $owner->createToken('ticket-create-test')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/user/ticket/save', [
            'subject' => 'website question', 'message' => 'website description', 'level' => 2,
        ])->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseCount('v2_ticket', 1);
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/user/ticket/save', [
            'subject' => 'duplicate question', 'message' => 'another description', 'level' => 1,
        ])->assertStatus(400);
        $this->assertDatabaseCount('v2_ticket', 1);
        $this->assertSame(1, DB::transactionLevel());
    }
}
