<?php

namespace Tests\Concerns;

use Anthropic\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Swaps the Anthropic SDK's HTTP transport for queued fake responses.
 */
trait FakesClaude
{
    /** @var list<array{request: RequestInterface}> */
    private array $claudeHistory = [];

    /**
     * @param  list<Response|\Throwable>  $responses
     */
    protected function fakeClaude(array $responses): void
    {
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.max_retries' => 0]);

        $this->claudeHistory = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->claudeHistory));

        $this->app->instance('anthropic.http', new GuzzleClient(['handler' => $stack]));
        $this->app->forgetInstance(Client::class);
    }

    /**
     * @return list<RequestInterface>
     */
    protected function claudeRequests(): array
    {
        return array_map(fn (array $entry) => $entry['request'], $this->claudeHistory);
    }

    /**
     * A Messages API reply whose text block holds the given JSON (or raw text), after a thinking block.
     *
     * @param  array<string, string>|string|null  $json
     * @param  array<string, int>  $usage
     * @param  array<string, mixed>  $extra
     */
    protected function claudeReply(array|string|null $json, string $stopReason = 'end_turn', array $usage = [], array $extra = []): Response
    {
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']];
        if ($json !== null) {
            $content[] = ['type' => 'text', 'text' => is_array($json) ? json_encode($json) : $json];
        }

        return new Response(200, ['content-type' => 'application/json'], json_encode([
            'id' => 'msg_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => $usage + ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ...$extra,
        ]));
    }
}
