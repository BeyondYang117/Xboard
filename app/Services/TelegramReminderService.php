<?php

namespace App\Services;

use App\Jobs\SendTelegramReminderJob;
use App\Models\Plugin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class TelegramReminderService
{
    public function dispatch(array $config): void
    {
        if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            return;
        }
        User::whereNotNull('telegram_id')->where('banned', false)->whereNotNull('plan_id')
            ->chunkById(200, function ($users) use ($config) {
                foreach ($users as $user) {
                    foreach ($this->remindersFor($user, $config) as $kind => $reminder) {
                        if (!Cache::has($reminder['key'])) {
                            SendTelegramReminderJob::dispatch($user->id, $kind, $reminder['key']);
                        }
                    }
                }
            });
    }

    public function remindersFor(User $user, array $config): array
    {
        $now = Carbon::now()->timestamp;
        if (!$user->telegram_id || $user->banned || !$user->plan_id
            || ($user->expired_at !== null && $user->expired_at <= $now)) {
            return [];
        }

        $reminders = [];
        if (filter_var($config['enable_expire_reminder'] ?? true, FILTER_VALIDATE_BOOLEAN) && $user->remind_expire && $user->expired_at !== null) {
            $days = (int) ceil(($user->expired_at - $now) / 86400);
            $configuredDays = preg_split('/[\s,，]+/u', (string) ($config['expire_reminder_days'] ?? '7,3,1'));
            $stages = array_filter(array_map('intval', $configuredDays), fn ($day) => $day > 0 && $day <= 365);
            if (in_array($days, $stages, true)) {
                $reminders['expire'] = [
                    'key' => $this->key($user, 'expire', [$user->expired_at, $days]),
                    'text' => "⏰ 套餐到期提醒\n\n您的套餐将在 {$days} 天内到期。\n到期时间："
                        . Carbon::createFromTimestamp($user->expired_at, config('app.timezone'))->format('Y-m-d H:i')
                        . "\n请及时登录网站续费，避免服务中断。",
                    'ttl' => 2 * 86400,
                ];
            }
        }

        $threshold = max(1, min(100, (int) ($config['traffic_reminder_percent'] ?? 80)));
        if (filter_var($config['enable_traffic_reminder'] ?? true, FILTER_VALIDATE_BOOLEAN) && $user->remind_traffic && $user->transfer_enable > 0
            && $user->getTrafficUsagePercentage() >= $threshold) {
            $remaining = number_format($user->getRemainingTraffic() / 1073741824, 2, '.', '');
            $reminders['traffic'] = [
                'key' => $this->key($user, 'traffic', [
                    $user->last_reset_at, $user->next_reset_at, $user->transfer_enable, $threshold,
                ]),
                'text' => "📊 流量不足提醒\n\n您的流量使用已达到 {$threshold}%。\n剩余流量：{$remaining} GB\n可在机器人菜单查看账户详情，或登录网站购买流量。",
                'ttl' => 86400,
            ];
        }
        return $reminders;
    }

    public function send(int $userId, string $kind, string $expectedKey): void
    {
        $plugin = Plugin::where('code', 'telegram')->where('is_enabled', true)->first();
        if (!$plugin || !admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            return;
        }
        $config = json_decode($plugin->config ?? '{}', true) ?: [];

        Cache::lock($expectedKey . ':lock', 25)->get(function () use ($userId, $kind, $expectedKey, $config) {
            $user = User::find($userId);
            if (!$user || User::where('telegram_id', $user->telegram_id)->count() !== 1) {
                return;
            }
            $reminder = $this->remindersFor($user, $config)[$kind] ?? null;
            if (!$reminder || $reminder['key'] !== $expectedKey || Cache::has($expectedKey)) {
                return;
            }
            app(TelegramService::class)->sendMessage($user->telegram_id, $reminder['text']);
            // Failed deliveries remain retryable; only successful sends consume the reminder.
            Cache::put($expectedKey, true, $reminder['ttl']);
        });
    }

    private function key(User $user, string $kind, array $state): string
    {
        return 'telegram:reminder:' . hash('sha256', json_encode([$user->id, $user->telegram_id, $kind, $state]));
    }
}
