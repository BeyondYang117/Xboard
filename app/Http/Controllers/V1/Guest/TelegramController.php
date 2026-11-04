<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TelegramController extends Controller
{
    protected ?object $msg = null;
    protected TelegramService $telegramService;
    protected UserService $userService;

    public function __construct(TelegramService $telegramService, UserService $userService)
    {
        $this->telegramService = $telegramService;
        $this->userService = $userService;
    }

    public function webhook(Request $request): void
    {
        $expectedToken = md5(admin_setting('telegram_bot_token'));
        if ($request->input('access_token') !== $expectedToken) {
            throw new ApiException('access_token is error', 401);
        }

        $data = $request->json()->all();
        if (isset($data['update_id']) && is_int($data['update_id'])) {
            $key = 'telegram:update:' . $expectedToken . ':' . $data['update_id'];
            Cache::lock($key . ':lock', 45)->block(3, function () use ($key, $data) {
                if (Cache::has($key)) {
                    return;
                }
                $this->processUpdate($data);
                Cache::put($key, true, 86400);
            });
            return;
        }
        $this->processUpdate($data);
    }

    private function processUpdate(array $data): void
    {
        $this->msg = null;
        $this->formatMessage($data);
        $this->formatCallbackQuery($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    private function handle(): void
    {
        if (!$this->msg)
            return;
        $msg = $this->msg;
        try {
            $this->processBotName($msg);
            HookManager::call('telegram.message.before', [$msg]);
            $handled = HookManager::filter('telegram.message.handle', false, [$msg]);
            if (!$handled) {
                HookManager::call('telegram.message.unhandled', [$msg]);
            }
            HookManager::call('telegram.message.after', [$msg]);
        } catch (\Throwable $e) {
            HookManager::call('telegram.message.error', [$msg, $e]);
            $this->telegramService->sendMessage($msg->chat_id, '系统繁忙，请稍后重试');
        }
    }

    private function processBotName(object $msg): void
    {
        if (!str_starts_with($msg->command, '/')) {
            return;
        }
        $commandParts = explode('@', $msg->command);

        if (count($commandParts) === 2) {
            $botName = $this->getBotName();
            if (strcasecmp($commandParts[1], $botName) === 0) {
                $msg->command = $commandParts[0];
            }
        }
    }

    private function getBotName(): string
    {
        return Cache::remember('telegram:bot-name:' . md5((string) admin_setting('telegram_bot_token')), 3600,
            fn () => $this->telegramService->getMe()->result->username
        );
    }

    private function formatMessage(array $data): void
    {
        if (!isset($data['message']['text']))
            return;

        $message = $data['message'];
        $text = preg_split('/\s+/u', trim($message['text']));

        $this->msg = (object) [
            'command' => $text[0],
            'args' => array_slice($text, 1),
            'chat_id' => $message['chat']['id'],
            'sender_id' => $message['from']['id'] ?? null,
            'message_id' => $message['message_id'],
            'message_type' => 'message',
            'text' => $message['text'],
            'is_private' => $message['chat']['type'] === 'private',
        ];

        if (isset($message['reply_to_message']['text'])) {
            $this->msg->message_type = 'reply_message';
            $this->msg->reply_text = $message['reply_to_message']['text'];
            $this->msg->reply_from_bot = (bool) ($message['reply_to_message']['from']['is_bot'] ?? false);
        }
    }

    private function formatCallbackQuery(array $data): void
    {
        $callback = $data['callback_query'] ?? null;
        if (!$callback || !isset($callback['id'])) {
            return;
        }
        $this->telegramService->answerCallbackQuery($callback['id']);
        $message = $callback['message'] ?? null;
        if (!$message || !isset($callback['data']) || ($message['chat']['type'] ?? '') !== 'private'
            || ($callback['from']['id'] ?? null) !== ($message['chat']['id'] ?? null)) {
            return;
        }
        $this->msg = (object) [
            'command' => '',
            'args' => [],
            'chat_id' => $message['chat']['id'],
            'sender_id' => $callback['from']['id'],
            'message_id' => $message['message_id'],
            'message_type' => 'callback_query',
            'callback_data' => $callback['data'],
            'text' => '',
            'is_private' => true,
        ];
    }

    private function formatChatJoinRequest(array $data): void
    {
        $joinRequest = $data['chat_join_request'] ?? null;
        if (!$joinRequest)
            return;

        $chatId = $joinRequest['chat']['id'] ?? null;
        $userId = $joinRequest['from']['id'] ?? null;

        if (!$chatId || !$userId)
            return;

        $user = User::where('telegram_id', $userId)->first();

        if (!$user) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        $this->telegramService->approveChatJoinRequest($chatId, $userId);
    }
}
