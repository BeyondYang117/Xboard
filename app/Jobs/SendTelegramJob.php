<?php

namespace App\Jobs;

use App\Exceptions\TelegramApiException;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected string $parseMode = 'markdown';

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, string $parseMode = 'markdown')
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->parseMode = $parseMode;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            app(TelegramService::class)->sendMessage($this->telegramId, $this->text, $this->parseMode);
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
