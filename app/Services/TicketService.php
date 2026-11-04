<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    public function reply($ticket, $message, $userId)
    {
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            $isAdmin = $userId !== $ticket->user_id;
            $ticket->reply_status = $isAdmin
                ? Ticket::REPLY_STATUS_REPLIED
                : Ticket::REPLY_STATUS_WAITING;
            $ticket->last_reply_user_id = $userId;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        $ticket = Ticket::where('id', $ticketId)->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticketMessage = $this->reply($ticket, $message, $userId);
        if (!$ticketMessage) {
            throw new ApiException('工单回复失败');
        }
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message)
    {
        return DB::transaction(function () use ($userId, $subject, $level, $message) {
            // Lock the owner even when there is no open ticket to lock yet.
            if (!User::whereKey($userId)->lockForUpdate()->first()) {
                throw new ApiException(__('The user does not exist'));
            }
            if (Ticket::where('status', Ticket::STATUS_OPENING)->where('user_id', $userId)->lockForUpdate()->first()) {
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level,
                'reply_status' => Ticket::REPLY_STATUS_WAITING,
                'last_reply_user_id' => $userId,
            ]);
            TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message,
            ]);
            return $ticket;
        });
    }

    public function replyByUser(int $ticketId, string $message, int $userId): TicketMessage
    {
        if (trim($message) === '') {
            throw new ApiException(__('Message cannot be empty'));
        }
        [$ticket, $ticketMessage] = DB::transaction(function () use ($ticketId, $message, $userId) {
            $ticket = Ticket::whereKey($ticketId)->where('user_id', $userId)->lockForUpdate()->first();
            if (!$ticket) {
                throw new ApiException(__('Ticket does not exist'));
            }
            if ((int) $ticket->status !== Ticket::STATUS_OPENING) {
                throw new ApiException(__('The ticket is closed and cannot be replied'));
            }
            $lastMessage = $ticket->messages()->orderByDesc('id')->lockForUpdate()->first();
            if ((int) admin_setting('ticket_must_wait_reply', 0) && (int) $lastMessage?->user_id === $userId) {
                throw new ApiException(__('Please wait for the technical enginneer to reply'));
            }
            $ticketMessage = $this->reply($ticket, $message, $userId);
            if (!$ticketMessage) {
                throw new ApiException(__('Ticket reply failed'));
            }
            return [$ticket, $ticketMessage];
        });
        HookManager::call('ticket.reply.user.after', $ticket);
        return $ticketMessage;
    }

    public function closeByUser(int $ticketId, int $userId): void
    {
        DB::transaction(function () use ($ticketId, $userId) {
            $ticket = Ticket::whereKey($ticketId)->where('user_id', $userId)->lockForUpdate()->first();
            if (!$ticket) {
                throw new ApiException(__('Ticket does not exist'));
            }
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->saveOrFail();
        });
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
