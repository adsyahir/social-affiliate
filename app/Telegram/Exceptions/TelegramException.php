<?php

namespace App\Telegram\Exceptions;

use RuntimeException;

/**
 * A Bot API call failed. Retryable failures (rate limits, server errors, network) are worth
 * retrying, after `retryAfter` seconds when Telegram says how long to wait.
 */
class TelegramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
