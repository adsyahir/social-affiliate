<?php

namespace App\Content\Exceptions;

/**
 * Text you provided breaks a posting rule. Nothing was saved; fix it and paste again.
 */
class ContentInvalidException extends ContentGenerationException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Please fix: '.implode(' ', $problems));
    }
}
