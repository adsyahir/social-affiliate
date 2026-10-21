<?php

namespace App\Content\Claude;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Content\Contracts\ContentGenerator;
use App\Content\Data\GeneratedText;
use App\Content\Data\Prompt;
use App\Content\Exceptions\ContentGenerationException;
use BackedEnum;
use Illuminate\Support\Facades\Log;

/**
 * Claude via the official Anthropic PHP SDK, with JSON output and a cached system prompt.
 */
class ClaudeContentGenerator implements ContentGenerator
{
    private const MAX_TOKENS = 16000;

    public function generate(Prompt $prompt): GeneratedText
    {
        if (blank(config('services.anthropic.api_key'))) {
            throw new ContentGenerationException('Set ANTHROPIC_API_KEY in .env to generate posts with Claude.');
        }

        $message = $this->send($prompt);

        $stopReason = $this->value($message->stopReason);

        if ($stopReason === 'refusal') {
            $category = $this->value($message->stopDetails?->category) ?: 'unspecified';

            throw new ContentGenerationException("Claude declined to write this post (category: {$category}). Edit the product details or try another model.");
        }

        if ($stopReason === 'max_tokens') {
            throw new ContentGenerationException('Claude\'s answer was cut off (max_tokens).');
        }

        $data = json_decode($this->text($message), true);

        if (! is_string($data['post'] ?? null) || ! is_string($data['link_reply'] ?? null)) {
            throw new ContentGenerationException('Claude\'s answer was not the expected JSON.', retryable: true);
        }

        return new GeneratedText(
            post: trim($data['post']),
            linkReply: trim($data['link_reply']),
            model: $this->value($message->model) ?: $prompt->model,
            usage: [
                'input_tokens' => (int) $message->usage->inputTokens,
                'output_tokens' => (int) $message->usage->outputTokens,
                'cache_read_input_tokens' => (int) ($message->usage->cacheReadInputTokens ?? 0),
                'cache_creation_input_tokens' => (int) ($message->usage->cacheCreationInputTokens ?? 0),
            ],
        );
    }

    private function send(Prompt $prompt): BetaMessage
    {
        try {
            return app(Client::class)->beta->messages->create(
                model: $prompt->model,
                maxTokens: self::MAX_TOKENS,
                system: [['type' => 'text', 'text' => $prompt->system, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: $prompt->messages,
                outputConfig: [
                    'effort' => (string) config('services.anthropic.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
                // If the model declines, the API retries on its default fallback model in the same call.
                fallbacks: 'default',
                betas: [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01->value],
            );
        } catch (RateLimitException $e) {
            Log::warning('Claude API rate limited', ['error' => $e->getMessage()]);

            throw new ContentGenerationException('Claude\'s rate limit was reached; try again in a minute.', retryable: true);
        } catch (InternalServerException $e) {
            Log::warning('Claude API server error', ['error' => $e->getMessage()]);

            throw new ContentGenerationException("Claude is overloaded or having trouble (HTTP {$e->status}); try again shortly.", retryable: true);
        } catch (APIConnectionException $e) {
            Log::warning('Claude API unreachable', ['error' => $e->getMessage()]);

            throw new ContentGenerationException('Could not reach the Claude API; check the server\'s internet connection.', retryable: true);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new ContentGenerationException('Claude rejected the API key; check ANTHROPIC_API_KEY.');
        } catch (APIStatusException $e) {
            Log::error('Claude API request failed', ['error' => $e->getMessage()]);

            throw new ContentGenerationException("Claude API error (HTTP {$e->status}): ".self::reason($e));
        } catch (APIException $e) {
            Log::error('Claude API error', ['error' => $e->getMessage()]);

            throw new ContentGenerationException('Claude API error; see the log for details.', retryable: true);
        }
    }

    /**
     * Claude's own one-line explanation, without the JSON around it.
     */
    private static function reason(APIStatusException $e): string
    {
        $message = data_get($e->body, 'error.message');

        return is_string($message) ? $message : 'see the log for details';
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post' => ['type' => 'string', 'description' => 'The main post, with no links.'],
                'link_reply' => ['type' => 'string', 'description' => 'The reply carrying the {link} placeholder and the disclosure.'],
            ],
            'required' => ['post', 'link_reply'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The answer is in the text block; thinking blocks may come first.
     */
    private function text(BetaMessage $message): string
    {
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        return '';
    }

    private function value(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
