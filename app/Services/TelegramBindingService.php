<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Plugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramBindingService
{
    public const LINK_TTL = 300;

    public function ensureBotEnabled(): void
    {
        if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')
            || !Plugin::where('code', 'telegram')->where('is_enabled', true)->exists()) {
            throw new ApiException('站点暂未开启 Telegram Bot');
        }
    }

    public function createLink(User $user): array
    {
        $this->ensureBotEnabled();
        $username = Cache::remember('telegram:bot-name:' . md5((string) admin_setting('telegram_bot_token')), 3600,
            fn () => app(TelegramService::class)->getMe()->result->username
        );
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_]{5,32}$/D', $username)) {
            throw new ApiException('机器人信息暂不可用，请稍后重试');
        }
        return Cache::lock($this->userKey($user->id) . ':lock', 10)->block(3, function () use ($user, $username) {
            return DB::transaction(function () use ($user, $username) {
                $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $this->checkUser($current);
                $this->revokeLink($current->id);
                $code = bin2hex(random_bytes(20));
                $hash = hash('sha256', $code);
                Cache::put($this->userKey($current->id), $hash, self::LINK_TTL);
                Cache::put($this->codeKey($code), [
                    'user_id' => $current->id,
                    'bot' => hash('sha256', (string) admin_setting('telegram_bot_token')),
                ], self::LINK_TTL);
                return [
                    'url' => "https://t.me/{$username}?start=bind_{$code}",
                    'expires_at' => now()->addSeconds(self::LINK_TTL)->timestamp,
                ];
            });
        });
    }

    public function previewLink(string $code): User
    {
        $this->ensureBotEnabled();
        $record = $this->linkRecord($code);
        $user = User::find($record['user_id']);
        if (!$user) {
            throw new ApiException('绑定链接已失效，请返回网站重新生成');
        }
        $this->checkUser($user);
        return $user;
    }

    public function bindWithLink(int $telegramId, string $code): User
    {
        $this->ensureBotEnabled();
        $record = $this->linkRecord($code);
        return Cache::lock('telegram:binding:' . $telegramId, 10)->block(3, function () use ($telegramId, $code, $record) {
            return Cache::lock($this->userKey($record['user_id']) . ':lock', 10)->block(3, function () use ($telegramId, $code) {
                return DB::transaction(function () use ($telegramId, $code) {
                    $record = $this->linkRecord($code);
                    $user = User::whereKey($record['user_id'])->lockForUpdate()->first();
                    if (!$user) {
                        throw new ApiException('绑定链接已失效，请返回网站重新生成');
                    }
                    $this->checkUser($user);
                    if (User::where('telegram_id', $telegramId)->exists()) {
                        throw new ApiException('当前 Telegram 已绑定账号，请先解绑后再绑定');
                    }
                    $user->telegram_id = $telegramId;
                    $user->saveOrFail();
                    $this->revokeLink($user->id);
                    return $user;
                });
            });
        });
    }

    // Website recovery must affect only the authenticated site account.
    public function unbindUser(int $userId): void
    {
        Cache::lock($this->userKey($userId) . ':lock', 10)->block(3, function () use ($userId) {
            DB::transaction(function () use ($userId) {
                $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $user->telegram_id = null;
                $user->saveOrFail();
                $this->revokeLink($userId);
            });
        });
    }

    private function checkUser(User $user): void
    {
        if ($user->banned) {
            throw new ApiException('账号不可用');
        }
        if ($user->telegram_id !== null) {
            throw new ApiException('该账号已经绑定了 Telegram 账号');
        }
    }

    private function linkRecord(string $code): array
    {
        $record = preg_match('/^[a-f0-9]{40}$/D', $code) ? Cache::get($this->codeKey($code)) : null;
        if (!is_array($record) || !isset($record['user_id'], $record['bot'])
            || !hash_equals($record['bot'], hash('sha256', (string) admin_setting('telegram_bot_token')))
            || Cache::get($this->userKey($record['user_id'])) !== hash('sha256', $code)) {
            throw new ApiException('绑定链接已失效，请返回网站重新生成');
        }
        return $record;
    }

    private function revokeLink(int $userId): void
    {
        $hash = Cache::get($this->userKey($userId));
        if (is_string($hash)) {
            Cache::forget('telegram:bind-link:code:' . $hash);
        }
        Cache::forget($this->userKey($userId));
    }

    private function userKey(int $userId): string
    {
        return 'telegram:bind-link:user:' . $userId;
    }

    private function codeKey(string $code): string
    {
        return 'telegram:bind-link:code:' . hash('sha256', $code);
    }

    public function bind(int $telegramId, string $token): User
    {
        return Cache::lock('telegram:binding:' . $telegramId, 10)->block(3, function () use ($telegramId, $token) {
            return DB::transaction(function () use ($telegramId, $token) {
                if (User::where('telegram_id', $telegramId)->exists()) {
                    throw new ApiException('当前 Telegram 已绑定账号，请先解绑后再绑定');
                }
                $user = User::where('token', $token)->lockForUpdate()->first();
                if (!$user || $user->banned) {
                    throw new ApiException('订阅地址无效或账号不可用');
                }
                if ($user->telegram_id !== null) {
                    throw new ApiException('该账号已经绑定了 Telegram 账号');
                }
                $user->telegram_id = $telegramId;
                $user->saveOrFail();
                $this->revokeLink($user->id);
                return $user;
            });
        });
    }

    public function unbind(int $telegramId): int
    {
        // Also lets users recover from duplicate bindings created by older versions.
        return Cache::lock('telegram:binding:' . $telegramId, 10)->block(3, fn () =>
            User::where('telegram_id', $telegramId)->update(['telegram_id' => null])
        );
    }
}
