<?php

namespace App\Exceptions;

class TelegramApiException extends ApiException
{
    public function __construct(string $message, int $code = 503, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $code);
    }

    public function isRetryable(): bool
    {
        return $this->getCode() === 429 || $this->getCode() >= 500;
    }
}
