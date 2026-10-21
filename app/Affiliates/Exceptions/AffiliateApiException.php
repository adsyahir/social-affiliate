<?php

namespace App\Affiliates\Exceptions;

use Throwable;

/**
 * The program's API failed. Retryable failures (rate limits, server errors, timeouts)
 * are worth retrying later with backoff; the rest need someone to look at them.
 */
class AffiliateApiException extends AffiliateException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $apiCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
