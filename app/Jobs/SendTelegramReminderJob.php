<?php

namespace App\Jobs;

use App\Exceptions\TelegramApiException;
use App\Services\TelegramReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramReminderJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [10, 30, 60];
    public $uniqueFor = 900;

    public function __construct(public int $userId, public string $kind, public string $reminderKey)
    {
        $this->onQueue('send_telegram');
    }

    public function uniqueId(): string
    {
        return $this->reminderKey;
    }

    public function handle(TelegramReminderService $service): void
    {
        try {
            $service->send($this->userId, $this->kind, $this->reminderKey);
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
