<?php

namespace App\Content\Contracts;

use App\Content\Data\GeneratedText;
use App\Content\Data\Prompt;
use App\Content\Exceptions\ContentGenerationException;

/**
 * One driver per AI provider. Drivers only send prompts; PromptBuilder and DraftWriter own
 * what is asked and what is accepted.
 */
interface ContentGenerator
{
    /**
     * @throws ContentGenerationException
     */
    public function generate(Prompt $prompt): GeneratedText;
}
