<?php

namespace App\Content\Data;

/**
 * A provider-neutral request: which model, the system prompt, and the conversation so far.
 */
final readonly class Prompt
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
    ) {}
}
