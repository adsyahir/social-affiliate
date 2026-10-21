<?php

namespace App\Social\Exceptions;

use Throwable;

/**
 * The platform's API failed. Retryable failures (rate limits, temporary errors, timeouts)
 * are worth retrying later with backoff; the rest need someone to look at them.
 */
class SocialApiException extends SocialException
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
