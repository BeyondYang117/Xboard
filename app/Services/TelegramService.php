<?php

namespace App\Services;

use App\Exceptions\TelegramApiException;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected PendingRequest $http;
    protected string $apiUrl;

    public function __construct(?string $token = null)
    {
        $botToken = admin_setting('telegram_bot_token', $token);
        $this->apiUrl = "https://api.telegram.org/bot{$botToken}/";

        $this->http = Http::connectTimeout(5)->timeout(10)
            ->withHeaders([
                'Accept' => 'application/json',
            ]);
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $replyMarkup = []): void
    {
        $text = $parseMode === 'markdown' ? str_replace('_', '\_', $text) : $text;
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ];
        // Telegram rejects a JSON null parse_mode; omit it for literal text.
        if ($parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        $this->request('sendMessage', $params);
    }

    public function answerCallbackQuery(string $callbackId): void
    {
        $this->request('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function approveChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function getMe(): object
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url): object
    {
        $result = $this->request('setWebhook', [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query', 'chat_join_request'],
        ]);
        return $result;
    }

    /**
     * 注册 Bot 命令列表
     */
    public function registerBotCommands(): void
    {
        try {
            $commands = HookManager::filter('telegram.bot.commands', []);

            if (empty($commands)) {
                Log::warning('没有找到任何 Telegram Bot 命令');
                return;
            }

            $this->request('setMyCommands', [
                'commands' => json_encode($commands),
                'scope' => json_encode(['type' => 'default'])
            ]);

            Log::info('Telegram Bot 命令注册成功', [
                'commands_count' => count($commands),
                'commands' => $commands
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Bot 命令注册失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 获取当前注册的命令列表
     */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands');
    }

    /**
     * 删除所有命令
     */
    public function deleteMyCommands(): object
    {
        return $this->request('deleteMyCommands');
    }

    public function sendMessageWithAdmin(string $message, bool $isStaff = false, string $parseMode = 'markdown'): void
    {
        $query = User::whereNotNull('telegram_id');
        $query->where(
            fn($q) => $q->where('is_admin', 1)
                ->when($isStaff, fn($q) => $q->orWhere('is_staff', 1))
        );
        $users = $query->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message, $parseMode);
        }
    }

    protected function request(string $method, array $params = []): object
    {
        try {
            $response = $this->http->post($this->apiUrl . $method, $params);
            $data = $response->object();
            if (!$response->successful() || !isset($data->ok) || !$data->ok) {
                $code = (int) ($data->error_code ?? ($response->successful() ? 502 : $response->status()));
                throw new TelegramApiException(
                    'Telegram API 错误: ' . ($data->description ?? '无效的响应'),
                    $code,
                    isset($data->parameters->retry_after) ? (int) $data->parameters->retry_after : null
                );
            }
            return $data;
        } catch (TelegramApiException $e) {
            Log::error('Telegram API 请求失败', ['method' => $method, 'code' => $e->getCode()]);
            throw $e;
        } catch (\Exception $e) {
            // HTTP exceptions contain the token in their URL. Never log or return it.
            Log::error('Telegram API 连接失败', ['method' => $method, 'exception' => get_class($e)]);
            throw new TelegramApiException('Telegram 服务暂时无法连接，请稍后重试');
        }
    }
}
