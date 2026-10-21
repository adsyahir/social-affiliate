<?php

namespace App\Content\Exceptions;

/**
 * The model's drafts kept breaking the posting rules, so nothing was saved.
 */
class ContentRejectedException extends ContentGenerationException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The draft broke the posting rules twice: '.implode(' ', $problems));
    }
}
