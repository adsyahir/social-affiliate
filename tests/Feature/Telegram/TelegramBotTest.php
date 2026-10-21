<?php

namespace Tests\Feature\Telegram;

use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    private const API = 'https://api.telegram.org/bot123:secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram' => [
            'bot_token' => '123:secret-token',
            'chat_id' => '555',
            'allowed_user_ids' => [],
            'webhook_secret' => 'hook-secret',
            'api_base_url' => 'https://api.telegram.org',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_send_message_uses_html_buttons_and_no_link_previews(): void
    {
        Http::fake([self::API.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);

        $id = app(TelegramBot::class)->sendMessage('555', '<b>Draft</b>', [[['text' => 'Approve', 'callback_data' => 'approve:1']]]);

        $this->assertSame(42, $id);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '555'
            && $r['text'] === '<b>Draft</b>'
            && $r['parse_mode'] === 'HTML'
            && $r['link_preview_options'] === ['is_disabled' => true]
            && $r['reply_markup'] === ['inline_keyboard' => [[['text' => 'Approve', 'callback_data' => 'approve:1']]]]);
    }

    public function test_editing_without_buttons_removes_them_and_unchanged_text_is_fine(): void
    {
        Http::fake([self::API.'/editMessageText' => Http::sequence()
            ->push(['ok' => true, 'result' => true])
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'], 400)]);
        $bot = app(TelegramBot::class);

        $bot->editMessageText('555', 42, 'Approved');
        $bot->editMessageText('555', 42, 'Approved');

        Http::assertSent(fn (Request $r) => $r['message_id'] === 42 && $r['reply_markup'] === ['inline_keyboard' => []]);
    }

    public function test_errors_are_classified_and_never_leak_the_token(): void
    {
        Log::spy();
        Http::fake([self::API.'/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]], 429)
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)
            ->push('Bad gateway', 502)]);
        $bot = app(TelegramBot::class);

        foreach ([[true, 7], [false, null], [true, null]] as [$retryable, $retryAfter]) {
            try {
                $bot->sendMessage('555', 'x');
                $this->fail('Expected an exception.');
            } catch (TelegramException $e) {
                $this->assertSame($retryable, $e->retryable);
                $this->assertSame($retryAfter, $e->retryAfter);
                $this->assertStringNotContainsString('secret-token', $e->getMessage());
            }
        }
    }

    public function test_connection_failures_are_retryable_and_scrubbed(): void
    {
        Log::spy();
        Http::fake(fn (Request $r) => throw new ConnectionException("Could not resolve host for {$r->url()}"));

        try {
            app(TelegramBot::class)->sendMessage('555', 'x');
            $this->fail('Expected an exception.');
        } catch (TelegramException $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $context) => ! str_contains(json_encode($context), 'secret-token'));
    }

    public function test_webhook_is_registered_with_the_secret_and_needed_updates(): void
    {
        Http::fake([self::API.'/setWebhook' => Http::response(['ok' => true, 'result' => true])]);

        app(TelegramBot::class)->setWebhook('https://example.com/telegram/webhook');

        Http::assertSent(fn (Request $r) => $r['url'] === 'https://example.com/telegram/webhook'
            && $r['secret_token'] === 'hook-secret'
            && $r['allowed_updates'] === ['message', 'callback_query']);
    }

    public function test_it_reports_when_it_is_not_configured(): void
    {
        $this->assertTrue(app(TelegramBot::class)->configured());

        config(['services.telegram.bot_token' => null]);
        $this->assertFalse(app(TelegramBot::class)->configured());

        $this->expectException(TelegramException::class);
        app(TelegramBot::class)->sendMessage('555', 'x');
    }
}
