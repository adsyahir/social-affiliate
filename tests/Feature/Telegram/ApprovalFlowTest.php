<?php

namespace Tests\Feature\Telegram;

use App\Content\DraftWriter;
use App\Enums\PostStatus;
use App\Enums\ProductStatus;
use App\Enums\SocialPlatform;
use App\Jobs\PublishPost;
use App\Jobs\RegenerateDraft;
use App\Jobs\SyncApprovalMessage;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Social\SocialPublisherFactory;
use App\Telegram\DraftMessage;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    private const THREADS = 'https://graph.threads.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.allowed_user_ids' => [],
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            'services.threads.base_url' => self::THREADS,
        ]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_draft_message_shows_the_post_and_buttons_and_escapes_html(): void
    {
        $post = $this->makePost(['content' => 'Murah <gila> & best']);

        $text = DraftMessage::text($post);

        $this->assertStringContainsString('Murah &lt;gila&gt; &amp; best', $text);
        $this->assertStringContainsString('https://s.shopee.com.my/x', $text);
        $this->assertStringContainsString($post->socialAccount->name, $text);
        $this->assertSame(
            [
                [['text' => '✅ Approve', 'callback_data' => "approve:{$post->id}"], ['text' => '❌ Reject', 'callback_data' => "reject:{$post->id}"], ['text' => '🔄 Regenerate', 'callback_data' => "regen:{$post->id}"]],
                [['text' => '➕ Add disclosure', 'callback_data' => "disc:{$post->id}"]],
            ],
            DraftMessage::keyboard($post),
        );
        $this->assertNull(DraftMessage::keyboard($this->makePost(['status' => PostStatus::Published])));
    }

    public function test_sync_sends_the_first_time_and_edits_afterwards(): void
    {
        $post = $this->makePost();
        Http::fake([
            self::TG.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            self::TG.'/editMessageText' => Http::response(['ok' => true, 'result' => true]),
        ]);

        SyncApprovalMessage::dispatchSync($post);
        $this->assertSame(77, $post->fresh()->telegram_message_id);
        $this->assertSame('555', $post->fresh()->telegram_chat_id);

        SyncApprovalMessage::dispatchSync($post->fresh());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 77);
    }

    public function test_sync_does_nothing_without_telegram_configured(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        SyncApprovalMessage::dispatchSync($this->makePost());

        Http::assertNothingSent();
    }

    public function test_approve_marks_the_post_and_queues_publishing_once(): void
    {
        Queue::fake();
        $post = $this->makePost(['telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->handle($this->tap("approve:{$post->id}"));
        $this->handle($this->tap("approve:{$post->id}"));

        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertSame('@adam', $post->approved_by);
        $this->assertNotNull($post->approved_at);
        Queue::assertPushed(PublishPost::class, 1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && str_contains((string) $r['text'], 'Already'));
    }

    public function test_reject_marks_the_post_rejected(): void
    {
        Queue::fake();
        $post = $this->makePost(['telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->handle($this->tap("reject:{$post->id}"));

        $this->assertSame(PostStatus::Rejected, $post->fresh()->status);
        Queue::assertNotPushed(PublishPost::class);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Rejected by @adam'));
    }

    public function test_regenerate_button_and_feedback_replies_queue_a_rewrite(): void
    {
        Queue::fake();
        $post = $this->makePost(['telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 78]])]);

        $this->handle($this->tap("regen:{$post->id}"));
        $this->handle(['update_id' => 2, 'message' => [
            'message_id' => 90, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'text' => 'Lebih pendek', 'reply_to_message' => ['message_id' => 77],
        ]]);

        Queue::assertPushed(RegenerateDraft::class, fn (RegenerateDraft $job) => $job->feedback === null);
        Queue::assertPushed(RegenerateDraft::class, fn (RegenerateDraft $job) => $job->feedback === 'Lebih pendek');
        // While rewriting, the buttons are taken away so nobody approves the old version.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['reply_markup'] === ['inline_keyboard' => []]);
    }

    public function test_a_feedback_reply_continues_the_draft_under_the_reply(): void
    {
        Queue::fake();
        $post = $this->makePost(['telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 78]])]);

        $this->handle(['update_id' => 2, 'message' => [
            'message_id' => 90, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'text' => 'Lebih pendek', 'reply_to_message' => ['message_id' => 77],
        ]]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && $r['reply_parameters']['message_id'] === 90 && str_contains($r['text'], 'Rewriting with your notes') && ! isset($r['reply_markup']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 77 && str_contains($r['text'], 'continues below'));
        // The rewrite, when done, updates the new message.
        $this->assertSame(78, $post->fresh()->telegram_message_id);
    }

    public function test_updates_from_other_chats_or_users_are_ignored(): void
    {
        Queue::fake();
        $post = $this->makePost();
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->handle($this->tap("approve:{$post->id}", chatId: 999));

        config(['services.telegram.allowed_user_ids' => ['1234']]);
        $this->handle($this->tap("approve:{$post->id}", userId: 9));

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_publishing_posts_then_replies_and_marks_the_product_used(): void
    {
        $post = $this->makePost(['status' => PostStatus::Approved]);
        $this->fakeThreadsAndTelegram();

        PublishPost::dispatchSync($post);

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame('media-main', $post->external_post_id);
        $this->assertSame('media-reply', $post->link_reply_id);
        $this->assertSame('https://www.threads.com/@x/post/1', $post->permalink);
        $this->assertNotNull($post->published_at);
        $this->assertSame(ProductStatus::Used, $post->product->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads') && ($r->data()['reply_to_id'] ?? null) === 'media-main'
            && $r['text'] === $post->link_reply_content);
    }

    public function test_publishing_resumes_without_posting_the_main_post_twice(): void
    {
        $post = $this->makePost(['status' => PostStatus::Approved, 'external_post_id' => 'media-main']);
        $this->fakeThreadsAndTelegram();

        PublishPost::dispatchSync($post);

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads') && ($r->data()['reply_to_id'] ?? null) === 'media-main');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/threads') && ! isset($r['reply_to_id']));
    }

    public function test_a_permanent_failure_marks_the_post_failed_and_offers_a_retry(): void
    {
        $post = $this->makePost(['status' => PostStatus::Approved, 'telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([
            self::THREADS.'/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400),
            self::TG.'/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        PublishPost::dispatchSync($post);

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('Session has expired', $post->last_error);
        $this->assertSame([[['text' => '🔁 Retry publishing', 'callback_data' => "retry:{$post->id}"]]], DraftMessage::keyboard($post));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Session has expired'));
    }

    public function test_retry_button_publishes_a_failed_post_again(): void
    {
        Queue::fake();
        $post = $this->makePost(['status' => PostStatus::Failed, 'telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->handle($this->tap("retry:{$post->id}"));

        $this->assertSame(PostStatus::Approved, $post->fresh()->status);
        Queue::assertPushed(PublishPost::class);
    }

    public function test_an_unexpected_publishing_crash_marks_the_post_failed_and_says_so(): void
    {
        $post = $this->makePost(['status' => PostStatus::Approved, 'telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->mock(SocialPublisherFactory::class, fn ($mock) => $mock->shouldReceive('make')->andThrow(new \RuntimeException('Database went away')));

        try {
            PublishPost::dispatchSync($post);
        } catch (\RuntimeException) {
            // The sync queue re-throws after calling failed().
        }

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('Database went away', $post->last_error);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Retry publishing') === false
            && $r['reply_markup'] === ['inline_keyboard' => [[['text' => '🔁 Retry publishing', 'callback_data' => "retry:{$post->id}"]]]]);
    }

    public function test_a_crashed_rewrite_gives_the_buttons_back(): void
    {
        $post = $this->makePost(['telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->mock(DraftWriter::class, fn ($mock) => $mock->shouldReceive('regenerate')->andThrow(new \RuntimeException('boom')));

        try {
            RegenerateDraft::dispatchSync($post, 'shorter');
        } catch (\RuntimeException) {
            // The sync queue re-throws after calling failed().
        }

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText')
            && str_contains($r['text'], 'Could not rewrite')
            && $r['reply_markup'] === ['inline_keyboard' => DraftMessage::keyboard($post)]);
    }

    public function test_long_feedback_is_shortened_in_the_chat(): void
    {
        Queue::fake();
        $post = $this->makePost(['telegram_chat_id' => '555', 'telegram_message_id' => 77]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 78]])]);

        $this->handle(['update_id' => 3, 'message' => [
            'message_id' => 91, 'from' => ['id' => 9], 'chat' => ['id' => 555],
            'text' => str_repeat('panjang ', 1000), 'reply_to_message' => ['message_id' => 77],
        ]]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && mb_strlen($r['text']) < 4096);
        // Only the echo in the chat is shortened; the model still gets the whole note.
        Queue::assertPushed(RegenerateDraft::class, fn (RegenerateDraft $job) => mb_strlen($job->feedback) > 4000);
    }

    public function test_publishing_skips_posts_that_are_no_longer_approved(): void
    {
        $post = $this->makePost(['status' => PostStatus::Rejected]);
        Http::fake();

        PublishPost::dispatchSync($post);

        $this->assertSame(PostStatus::Rejected, $post->fresh()->status);
        Http::assertNothingSent();
    }

    private function fakeThreadsAndTelegram(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_starts_with($request->url(), self::TG) => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
                str_ends_with($path, '/threads') => Http::response(['id' => isset($request['reply_to_id']) ? 'c-reply' : 'c-main']),
                str_ends_with($path, '/threads_publish') => Http::response(['id' => $request['creation_id'] === 'c-main' ? 'media-main' : 'media-reply']),
                default => Http::response(['status' => 'FINISHED', 'permalink' => 'https://www.threads.com/@x/post/1']),
            };
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function tap(string $data, int $chatId = 555, int $userId = 9): array
    {
        return ['update_id' => 1, 'callback_query' => [
            'id' => 'cb-'.uniqid(),
            'from' => ['id' => $userId, 'username' => 'adam'],
            'message' => ['message_id' => 77, 'chat' => ['id' => $chatId]],
            'data' => $data,
        ]];
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function handle(array $update): void
    {
        app(UpdateHandler::class)->handle($update);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePost(array $attributes = []): Post
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create(['platform' => SocialPlatform::Threads, 'external_id' => '1789', 'access_token' => 'tok']);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);

        return Post::factory()->create([
            'campaign_id' => $campaign->id,
            'product_id' => Product::factory()->for($affiliate)->create()->id,
            'social_account_id' => $account->id,
            'content' => 'Barang murah tapi best',
            'link_reply_content' => "Sini https://s.shopee.com.my/x\n\nLink affiliate ya.",
            ...$attributes,
        ]);
    }
}
