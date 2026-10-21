<?php

namespace Tests\Feature\Content;

use App\Content\Claude\ClaudeContentGenerator;
use App\Content\Data\Prompt;
use App\Content\Exceptions\ContentGenerationException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class ClaudeContentGeneratorTest extends TestCase
{
    use FakesClaude;

    public function test_request_uses_the_prompt_caching_structured_output_and_fallbacks(): void
    {
        config(['services.anthropic.effort' => 'low']);
        $this->fakeClaude([$this->claudeReply(['post' => 'Hi', 'link_reply' => 'Get it: {link}'])]);

        app(ClaudeContentGenerator::class)->generate($this->prompt());

        $request = $this->claudeRequests()[0];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringEndsWith('/v1/messages?beta=true', (string) $request->getUri());
        $this->assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('test-key', $request->getHeaderLine('x-api-key'));
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame([['type' => 'text', 'text' => 'SYSTEM', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'USER']], $body['messages']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(['post', 'link_reply'], $body['output_config']['format']['schema']['required']);
        $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
    }

    public function test_reply_is_parsed_after_thinking_blocks_with_usage(): void
    {
        $this->fakeClaude([$this->claudeReply(['post' => 'Murah gila!', 'link_reply' => 'Sini: {link}'], usage: [
            'input_tokens' => 120, 'output_tokens' => 45, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 0,
        ])]);

        $result = app(ClaudeContentGenerator::class)->generate($this->prompt());

        $this->assertSame('Murah gila!', $result->post);
        $this->assertSame('Sini: {link}', $result->linkReply);
        $this->assertSame('claude-sonnet-5-5', $result->model);
        $this->assertSame(['input_tokens' => 120, 'output_tokens' => 45, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 0], $result->usage);
    }

    public function test_refusals_and_truncation_are_not_retryable(): void
    {
        $this->fakeClaude([
            $this->claudeReply(null, stopReason: 'refusal', extra: ['stop_details' => ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => 'x']]),
            $this->claudeReply(null, stopReason: 'max_tokens'),
        ]);
        $generator = app(ClaudeContentGenerator::class);

        foreach (['declined' => 'general_harms', 'cut off' => 'max_tokens'] as $expected => $detail) {
            try {
                $generator->generate($this->prompt());
                $this->fail("Expected an exception mentioning {$expected}.");
            } catch (ContentGenerationException $e) {
                $this->assertFalse($e->retryable);
                $this->assertStringContainsString($detail, $e->getMessage());
            }
        }
    }

    public function test_unreadable_json_is_retryable(): void
    {
        $this->fakeClaude([$this->claudeReply('not json at all')]);

        try {
            app(ClaudeContentGenerator::class)->generate($this->prompt());
            $this->fail('Expected an exception.');
        } catch (ContentGenerationException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_api_errors_are_classified(): void
    {
        $error = fn (int $status, string $type) => new Response($status, ['content-type' => 'application/json'], json_encode([
            'type' => 'error', 'error' => ['type' => $type, 'message' => "{$type} happened"],
        ]));
        $this->fakeClaude([
            $error(429, 'rate_limit_error'),
            $error(529, 'overloaded_error'),
            $error(401, 'authentication_error'),
            $error(400, 'invalid_request_error'),
            new ConnectException('Connection refused', new PsrRequest('POST', 'https://api.anthropic.com/v1/messages')),
        ]);
        $generator = app(ClaudeContentGenerator::class);

        foreach (['rate limit' => true, 'overloaded' => true, 'auth' => false, 'bad request' => false, 'network' => true] as $case => $retryable) {
            try {
                $generator->generate($this->prompt());
                $this->fail("Expected an exception for {$case}.");
            } catch (ContentGenerationException $e) {
                $this->assertSame($retryable, $e->retryable, $case);
                $this->assertStringNotContainsString('{', $e->getMessage(), "{$case}: message should be readable, not raw JSON");
                $this->assertStringNotContainsString('test-key', $e->getMessage(), $case);
            }
        }
    }

    public function test_missing_api_key_fails_before_calling(): void
    {
        $this->fakeClaude([]);
        config(['services.anthropic.api_key' => null]);

        $this->expectException(ContentGenerationException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY');

        app(ClaudeContentGenerator::class)->generate($this->prompt());
    }

    private function prompt(): Prompt
    {
        return new Prompt(model: 'claude-sonnet-5-5', system: 'SYSTEM', messages: [['role' => 'user', 'content' => 'USER']]);
    }
}
