<?php

namespace App\Content\Exceptions;

use RuntimeException;

/**
 * The model could not produce a draft. Retryable failures (rate limits, overload, network,
 * unreadable output) are worth retrying later; the rest need someone to look at them.
 */
class ContentGenerationException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
