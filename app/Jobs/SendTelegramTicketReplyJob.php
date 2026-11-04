<?php

namespace App\Jobs;

use App\Exceptions\TelegramApiException;
use App\Services\TelegramTicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramTicketReplyJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [10, 30, 60];
    public $uniqueFor = 86400;

    public function __construct(public int $messageId, public int $userId, public int $telegramId)
    {
        $this->onQueue('send_telegram');
    }

    public function uniqueId(): string
    {
        return "{$this->messageId}:{$this->userId}:{$this->telegramId}";
    }

    public function handle(TelegramTicketService $service): void
    {
        try {
            $service->notifyReply($this->messageId, $this->userId, $this->telegramId);
        } catch (TelegramApiException $e) {
            if (!$e->isRetryable()) {
                $this->fail($e);
                return;
            }
            if ($e->retryAfter !== null) {
                $this->release(max(1, $e->retryAfter));
                return;
            }
            throw $e;
        }
    }
}
