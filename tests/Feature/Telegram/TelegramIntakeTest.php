<?php

namespace Tests\Feature\Telegram;

use App\Filament\Resources\Posts\Pages\EditPost;
use App\Jobs\SyncApprovalMessage;
use App\Models\Post;
use App\Models\User;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class TelegramIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.webhook_secret' => 'hook-secret',
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            'app.url' => 'https://affiliate.example.com',
        ]);
        Http::preventStrayRequests();
    }

    public function test_webhook_passes_genuine_updates_to_the_handler(): void
    {
        $update = ['update_id' => 1, 'callback_query' => ['id' => 'x', 'data' => 'approve:1']];
        $this->mock(UpdateHandler::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once()->with($update));

        $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    }

    public function test_webhook_rejects_calls_without_the_secret(): void
    {
        $this->mock(UpdateHandler::class, fn (MockInterface $mock) => $mock->shouldNotReceive('handle'));

        $this->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->postJson('/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();

        config(['services.telegram.webhook_secret' => null]);
        $this->postJson('/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertForbidden();
    }

    public function test_webhook_answers_ok_even_when_handling_fails(): void
    {
        // Otherwise Telegram keeps re-sending the same update.
        $this->mock(UpdateHandler::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->andThrow(new \RuntimeException('boom')));

        $this->postJson('/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    }

    public function test_poll_handles_updates_and_remembers_the_offset(): void
    {
        Cache::forget('telegram.update_offset');
        Http::fake([self::TG.'/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 10, 'message' => ['text' => 'hi']],
            ['update_id' => 11, 'message' => ['text' => 'hi again']],
        ]])]);
        $this->mock(UpdateHandler::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->twice());

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame(12, Cache::get('telegram.update_offset'));
        Http::assertSent(fn (Request $r) => $r['offset'] === 0);
    }

    public function test_set_webhook_registers_the_app_url(): void
    {
        Http::fake([self::TG.'/setWebhook' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('telegram:set-webhook')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r['url'] === 'https://affiliate.example.com/telegram/webhook' && $r['secret_token'] === 'hook-secret');
    }

    public function test_set_webhook_needs_https_and_a_secret(): void
    {
        Http::fake();

        $this->artisan('telegram:set-webhook', ['url' => 'http://insecure.example.com/telegram/webhook'])->assertFailed();

        config(['services.telegram.webhook_secret' => null]);
        $this->artisan('telegram:set-webhook')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_send_to_telegram_button_on_a_post(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $post = Post::factory()->create(['link_reply_content' => 'x']);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->callAction('sendToTelegram')
            ->assertNotified();

        Queue::assertPushed(SyncApprovalMessage::class);
    }
}
