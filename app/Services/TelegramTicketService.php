<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramTicketReplyJob;
use App\Models\Plugin;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class TelegramTicketService
{
    public function __construct(private TelegramService $telegram, private TicketService $tickets)
    {
    }

    public function showList(User $user, int $page = 1): void
    {
        $this->assertUser($user);
        $page = max(1, min(10000, $page));
        $tickets = Ticket::where('user_id', $user->id)->orderByDesc('id')->simplePaginate(5, ['*'], 'page', $page);
        $text = "📮 我的工单\n\n";
        $rows = [];
        foreach ($tickets as $ticket) {
            $status = $this->status($ticket);
            $subject = mb_strimwidth($ticket->subject, 0, 70, '…');
            $text .= "#{$ticket->id} · {$status}\n{$subject}\n\n";
            $rows[] = [['text' => "#{$ticket->id} {$subject}", 'callback_data' => "tg:ticket:{$ticket->id}:1"]];
        }
        if ($tickets->isEmpty()) {
            $text .= '暂无工单。点击下方按钮联系客服。';
        }
        $navigation = [];
        if ($page > 1) {
            $navigation[] = ['text' => '上一页', 'callback_data' => 'tg:tickets:' . ($page - 1)];
        }
        if ($tickets->hasMorePages()) {
            $navigation[] = ['text' => '下一页', 'callback_data' => 'tg:tickets:' . ($page + 1)];
        }
        if ($navigation) {
            $rows[] = $navigation;
        }
        $rows[] = [['text' => '✍️ 新建工单', 'callback_data' => 'tg:newticket'], ['text' => '返回菜单', 'callback_data' => 'tg:menu']];
        $this->send($user, $text, $rows);
    }

    public function showTicket(User $user, int $ticketId, int $page = 1): void
    {
        $ticket = $this->ownedTicket($user, $ticketId);
        $page = max(1, min(10000, $page));
        $messages = $ticket->messages()->orderByDesc('id')->simplePaginate(2, ['*'], 'page', $page);
        $text = "📨 我的工单 #{$ticket->id}\n" . mb_strimwidth($ticket->subject, 0, 180, '…')
            . "\n状态：" . $this->status($ticket) . "\n\n";
        foreach ($messages->getCollection()->reverse() as $message) {
            $author = (int) $message->user_id === (int) $user->id ? '我' : '客服';
            $at = Carbon::createFromTimestamp($message->created_at, config('app.timezone'))->format('m-d H:i');
            $text .= "{$author} · {$at}\n" . $this->excerpt($message->message, 1200) . "\n\n";
        }
        $rows = [];
        $navigation = [];
        if ($page > 1) {
            $navigation[] = ['text' => '较新记录', 'callback_data' => "tg:ticket:{$ticket->id}:" . ($page - 1)];
        }
        if ($messages->hasMorePages()) {
            $navigation[] = ['text' => '更早记录', 'callback_data' => "tg:ticket:{$ticket->id}:" . ($page + 1)];
        }
        if ($navigation) {
            $rows[] = $navigation;
        }
        if ((int) $ticket->status === Ticket::STATUS_OPENING) {
            $rows[] = [['text' => '回复工单', 'callback_data' => "tg:ticket_reply:{$ticket->id}"],
                ['text' => '关闭工单', 'callback_data' => "tg:ticket_close:{$ticket->id}"]];
            $text .= '可点击“回复工单”，或直接回复这条消息。';
        }
        $rows[] = [['text' => '返回工单列表', 'callback_data' => 'tg:tickets']];
        $this->send($user, $text, $rows);
    }

    public function beginCreate(User $user): void
    {
        $this->assertUser($user);
        $this->cancelDraft($user->telegram_id);
        $open = Ticket::where('user_id', $user->id)->where('status', Ticket::STATUS_OPENING)->first();
        if ($open) {
            $this->send($user, "您已有未关闭的工单 #{$open->id}，请继续在该工单中沟通。", [
                [['text' => '查看工单', 'callback_data' => "tg:ticket:{$open->id}:1"]],
            ]);
            return;
        }
        $this->saveDraft($user, ['step' => 'subject']);
        $this->prompt($user, "📝 新建工单：标题\n\n请发送问题标题（不超过 120 字）。\n15 分钟内有效，发送 /cancel 取消。", '请输入工单标题');
    }

    public function beginReply(User $user, int $ticketId): void
    {
        $ticket = $this->ownedTicket($user, $ticketId);
        $this->assertOpen($ticket);
        $this->saveDraft($user, ['step' => 'reply', 'ticket_id' => $ticketId]);
        $this->prompt($user, "✍️ 回复工单 #{$ticketId}\n\n请发送回复内容（不超过 3500 字）。\n15 分钟内有效，发送 /cancel 取消。", '请输入回复内容');
    }

    public function hasDraft(int $telegramId): bool
    {
        return Cache::has($this->draftKey($telegramId));
    }

    public function cancelDraft(int $telegramId): void
    {
        Cache::forget($this->draftKey($telegramId));
    }

    public function continueDraft(User $user, string $text): void
    {
        $this->assertUser($user);
        Cache::lock($this->draftKey($user->telegram_id) . ':lock', 45)->block(3, function () use ($user, $text) {
            $draft = Cache::get($this->draftKey($user->telegram_id));
            if (!$draft || ($draft['user_id'] ?? null) !== $user->id) {
                $this->cancelDraft($user->telegram_id);
                throw new ApiException('该输入步骤已结束，请重新打开工单菜单。');
            }
            $text = trim($text);
            if ($draft['step'] === 'subject') {
                if ($text === '' || mb_strlen($text) > 120) {
                    throw new ApiException('工单标题不能为空，且不能超过 120 字。');
                }
                $this->saveDraft($user, ['step' => 'message', 'subject' => preg_replace('/\s+/u', ' ', $text)]);
                $this->prompt($user, "📝 新建工单：内容\n\n请描述您遇到的问题（不超过 3500 字）。\n发送后将提交给客服，发送 /cancel 取消。", '请输入问题详情');
                return;
            }
            $this->validateMessage($text);
            if ($draft['step'] === 'reply') {
                $this->reply($user, (int) $draft['ticket_id'], $text);
                return;
            }
            if ($draft['step'] !== 'message' || !isset($draft['subject'])) {
                $this->cancelDraft($user->telegram_id);
                throw new ApiException('该输入步骤已结束，请重新打开工单菜单。');
            }
            $ticket = $this->tickets->createTicket($user->id, $draft['subject'], 1, $text);
            $this->cancelDraft($user->telegram_id);
            HookManager::call('ticket.create.after', $ticket);
            $this->send($user, "✅ 工单 #{$ticket->id} 已提交，客服回复后会通知您。", [
                [['text' => '查看工单', 'callback_data' => "tg:ticket:{$ticket->id}:1"]],
                [['text' => '返回菜单', 'callback_data' => 'tg:menu']],
            ]);
        });
    }

    public function reply(User $user, int $ticketId, string $text): void
    {
        $this->assertUser($user);
        $this->validateMessage($text);
        $this->tickets->replyByUser($ticketId, trim($text), $user->id);
        $this->cancelDraft($user->telegram_id);
        $this->send($user, "✅ 工单 #{$ticketId} 回复已发送。", [
            [['text' => '查看工单', 'callback_data' => "tg:ticket:{$ticketId}:1"]],
        ]);
    }

    public function beginClose(User $user, int $ticketId): void
    {
        $ticket = $this->ownedTicket($user, $ticketId);
        $this->assertOpen($ticket);
        $this->send($user, "确认关闭工单 #{$ticketId}？关闭后将无法继续回复。", [
            [['text' => '确认关闭', 'callback_data' => "tg:ticket_close_confirm:{$ticketId}"],
                ['text' => '取消', 'callback_data' => "tg:ticket:{$ticketId}:1"]],
        ]);
    }

    public function close(User $user, int $ticketId): void
    {
        $this->assertUser($user);
        $this->tickets->closeByUser($ticketId, $user->id);
        $this->cancelDraft($user->telegram_id);
        $this->send($user, "✅ 工单 #{$ticketId} 已关闭。", [[['text' => '返回工单列表', 'callback_data' => 'tg:tickets']]]);
    }

    public function queueReply(Ticket $ticket, TicketMessage $message): void
    {
        $user = User::find($ticket->user_id);
        if (!$user || !$user->telegram_id || $user->banned || (int) $message->user_id === (int) $user->id) {
            return;
        }
        SendTelegramTicketReplyJob::dispatch($message->id, $user->id, $user->telegram_id)->afterCommit();
    }

    public function notifyReply(int $messageId, int $userId, int $telegramId): void
    {
        $plugin = Plugin::where('code', 'telegram')->where('is_enabled', true)->first();
        if (!$plugin || !admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            return;
        }
        $config = json_decode($plugin->config ?? '{}', true) ?: [];
        if (!filter_var($config['enable_user_tickets'] ?? true, FILTER_VALIDATE_BOOLEAN)
            || !filter_var($config['enable_user_ticket_notify'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        $key = 'telegram:ticket-reply:' . hash('sha256', json_encode([
            admin_setting('telegram_bot_token'), $messageId, $userId, $telegramId,
        ]));
        Cache::lock($key . ':lock', 25)->get(function () use ($key, $messageId, $userId, $telegramId) {
            $user = User::find($userId);
            if (!$user || $user->banned || (int) $user->telegram_id !== $telegramId
                || User::where('telegram_id', $telegramId)->count() !== 1 || Cache::has($key)) {
                return;
            }
            $message = TicketMessage::with('ticket')->find($messageId);
            if (!$message || !$message->ticket || (int) $message->ticket->user_id !== $userId
                || (int) $message->user_id === $userId) {
                return;
            }
            $ticket = $message->ticket;
            $text = "📬 客服回复 #{$ticket->id}\n" . mb_strimwidth($ticket->subject, 0, 180, '…')
                . "\n\n" . $this->excerpt($message->message, 2800);
            if ((int) $ticket->status === Ticket::STATUS_OPENING) {
                $text .= "\n\n可直接回复这条消息继续沟通。";
            } else {
                $text .= "\n\n该工单已关闭。";
            }
            $this->send($user, $text, [[['text' => '查看工单', 'callback_data' => "tg:ticket:{$ticket->id}:1"]]]);
            Cache::put($key, true, 7 * 86400);
        });
    }

    private function ownedTicket(User $user, int $ticketId): Ticket
    {
        $this->assertUser($user);
        $ticket = Ticket::whereKey($ticketId)->where('user_id', $user->id)->first();
        if (!$ticket) {
            throw new ApiException(__('Ticket does not exist'));
        }
        return $ticket;
    }

    private function assertUser(User $user): void
    {
        if ($user->banned || !$user->telegram_id || $user->telegram_id <= 0) {
            throw new ApiException('当前账号不可使用 Telegram 工单，请登录网站联系客服。');
        }
    }

    private function assertOpen(Ticket $ticket): void
    {
        if ((int) $ticket->status !== Ticket::STATUS_OPENING) {
            throw new ApiException(__('The ticket is closed and cannot be replied'));
        }
    }

    private function validateMessage(string $text): void
    {
        if (trim($text) === '' || mb_strlen($text) > 3500) {
            throw new ApiException('工单内容不能为空，且不能超过 3500 字。');
        }
    }

    private function status(Ticket $ticket): string
    {
        return (int) $ticket->status === Ticket::STATUS_CLOSED ? '已关闭'
            : ((int) $ticket->reply_status === Ticket::REPLY_STATUS_WAITING ? '等待客服回复' : '客服已回复');
    }

    private function excerpt(string $text, int $width): string
    {
        return mb_strwidth($text) <= $width ? $text
            : mb_strimwidth($text, 0, $width, '…') . "\n（内容较长，完整内容请登录网站查看）";
    }

    private function draftKey(int $telegramId): string
    {
        return 'telegram:ticket-draft:' . $telegramId;
    }

    private function saveDraft(User $user, array $draft): void
    {
        Cache::put($this->draftKey($user->telegram_id), $draft + ['user_id' => $user->id], 900);
    }

    private function prompt(User $user, string $text, string $placeholder): void
    {
        $this->telegram->sendMessage($user->telegram_id, $text, '', [
            'force_reply' => true, 'input_field_placeholder' => $placeholder,
        ]);
    }

    private function send(User $user, string $text, array $rows): void
    {
        $this->telegram->sendMessage($user->telegram_id, $text, '', ['inline_keyboard' => $rows]);
    }
}
