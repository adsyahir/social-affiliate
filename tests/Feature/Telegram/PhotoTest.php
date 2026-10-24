<?php

namespace Tests\Feature\Telegram;

use App\Content\DraftWriter;
use App\Enums\ContentSource;
use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Jobs\PublishPost;
use App\Jobs\RegenerateDraft;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Social\Data\PostContent;
use App\Social\Exceptions\SocialApiException;
use App\Social\SocialPublisherFactory;
use App\Telegram\DraftMessage;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Photos sent to the bot: saved on the server, attached to the post and the product, and published.
 */
class PhotoTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    private const FILES = 'https://api.telegram.org/file/bot123:tok';

    // A 1x1 PNG.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.allowed_user_ids' => [],
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            'app.url' => 'https://affiliate.example.com',
            'filesystems.disks.public.url' => 'https://affiliate.example.com/storage',
        ]);
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_a_photo_with_a_caption_fills_the_waiting_post_and_becomes_its_image(): void
    {
        [$post, $product] = $this->waitingPost();
        $this->fakeTelegram();

        $this->replyWithPhoto(50, "POST:\nTumbler ni padu.\nREPLY:\nSini {link}");

        $post->refresh();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Tumbler ni padu.', $post->content);
        $this->assertStringStartsWith('https://affiliate.example.com/storage/post-images/', $post->image_url);
        $this->assertSame($post->image_url, $product->fresh()->image_url);
        Storage::disk('public')->assertExists('post-images/'.basename($post->image_url));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], '🖼'));
        // The bot token never ends up in the stored link.
        $this->assertStringNotContainsString('123:tok', $post->image_url);
    }

    public function test_a_photo_without_a_caption_is_saved_and_the_post_still_waits_for_text(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram();

        $this->replyWithPhoto(50, null);

        $this->assertSame(PostStatus::Pending, $post->fresh()->status);
        $this->assertNotNull($post->fresh()->image_url);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Photo saved'));
    }

    public function test_a_photo_replaces_the_image_of_your_draft_and_keeps_its_text(): void
    {
        [$post] = $this->waitingPost();
        app(DraftWriter::class)->fillFromText($post, 'Mine');
        $post->update(['image_url' => 'https://affiliate.example.com/storage/post-images/old.jpg']);
        $this->fakeTelegram();

        $this->replyWithPhoto(50, null);

        $this->assertSame('Mine', $post->fresh()->content);
        $this->assertNotSame('https://affiliate.example.com/storage/post-images/old.jpg', $post->fresh()->image_url);
    }

    public function test_a_caption_on_an_ai_draft_is_feedback_for_a_rewrite(): void
    {
        Queue::fake();
        [$post] = $this->waitingPost(ContentSource::Ai);
        $post->update(['status' => PostStatus::Draft, 'content' => 'AI text', 'generation' => ['source' => 'ai', 'link' => 'https://s.shopee.com.my/x']]);
        $this->fakeTelegram();

        $this->replyWithPhoto(50, 'Lebih pendek');

        $this->assertNotNull($post->fresh()->image_url);
        Queue::assertPushed(RegenerateDraft::class, fn (RegenerateDraft $job) => $job->feedback === 'Lebih pendek');
    }

    public function test_a_photo_to_a_published_post_is_refused(): void
    {
        [$post] = $this->waitingPost();
        $post->update(['status' => PostStatus::Published, 'content' => 'Live']);
        $this->fakeTelegram();

        $this->replyWithPhoto(50, null);

        $this->assertNull($post->fresh()->image_url);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/getFile'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'already published'));
    }

    public function test_photos_over_threads_limit_are_refused(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram(fileSize: 6 * 1024 * 1024);

        $this->replyWithPhoto(50, null);

        $this->assertNull($post->fresh()->image_url);
        Http::assertSent(fn (Request $r) => str_contains((string) ($r->data()['text'] ?? ''), '5 MB'));
    }

    public function test_files_that_are_not_images_are_refused(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram(body: '%PDF-1.7 not an image');

        app(UpdateHandler::class)->handle(['update_id' => 9, 'message' => [
            'message_id' => 91, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'document' => ['file_id' => 'doc-1', 'file_size' => 2000, 'mime_type' => 'image/jpeg'],
            'reply_to_message' => ['message_id' => 50],
        ]]);

        $this->assertNull($post->fresh()->image_url);
        Storage::disk('public')->assertDirectoryEmpty('post-images');
        Http::assertSent(fn (Request $r) => str_contains((string) ($r->data()['text'] ?? ''), 'JPEG or PNG'));
    }

    public function test_a_photo_sent_without_replying_goes_to_the_only_waiting_post(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram();

        app(UpdateHandler::class)->handle(['update_id' => 8, 'message' => [
            'message_id' => 92, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'photo' => [['file_id' => 'small', 'file_size' => 100, 'width' => 90], ['file_id' => 'big', 'file_size' => 2000, 'width' => 1280]],
        ]]);

        $this->assertNotNull($post->fresh()->image_url);
        // The largest size is the one downloaded.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getFile') && $r['file_id'] === 'big');
    }

    public function test_the_draft_message_shows_the_photo(): void
    {
        [$post] = $this->waitingPost();
        app(DraftWriter::class)->fillFromText($post, 'Mine');
        $post->update(['image_url' => 'https://affiliate.example.com/storage/post-images/a.jpg']);

        $this->assertStringContainsString('<a href="https://affiliate.example.com/storage/post-images/a.jpg">', DraftMessage::text($post->fresh()));
    }

    public function test_threads_posts_go_out_with_the_image(): void
    {
        [$post] = $this->waitingPost();
        $post->update(['status' => PostStatus::Approved, 'content' => 'Padu', 'link_reply_content' => 'Sini', 'image_url' => 'https://affiliate.example.com/storage/post-images/a.jpg']);
        Http::fake([
            'graph.threads.net/v1.0/me/threads_publish*' => Http::response(['id' => 'media-1']),
            'graph.threads.net/v1.0/me/threads*' => Http::response(['id' => 'container-1']),
            'graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
            'graph.threads.net/v1.0/media-1*' => Http::response(['permalink' => 'https://www.threads.com/@a/post/x']),
            self::TG.'/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        config(['services.threads.base_url' => 'https://graph.threads.net']);

        PublishPost::dispatchSync($post);

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me/threads') && ($r['media_type'] ?? null) === 'IMAGE'
            && $r['image_url'] === 'https://affiliate.example.com/storage/post-images/a.jpg' && ! isset($r['reply_to_id']));
        // The link reply stays text only.
        Http::assertSent(fn (Request $r) => isset($r['reply_to_id']) && $r['media_type'] === 'TEXT');
    }

    public function test_a_new_post_starts_with_your_last_photo_of_the_product_but_not_catalogue_images(): void
    {
        [$post, $product] = $this->waitingPost();
        $writer = app(DraftWriter::class);

        $product->update(['image_url' => 'https://affiliate.example.com/storage/post-images/mine.jpg']);
        $this->assertSame('https://affiliate.example.com/storage/post-images/mine.jpg', $writer->createPending($product, $post->socialAccount, $post->campaign)->image_url);

        // A Shopee catalogue image is never attached without you choosing it.
        $product->update(['image_url' => 'https://cf.shopee.com.my/file/abc123']);
        $this->assertNull($writer->createPending($product, $post->socialAccount, $post->campaign)->image_url);
    }

    public function test_a_post_does_not_borrow_the_product_photo_later(): void
    {
        [$post, $product] = $this->waitingPost();
        $product->update(['image_url' => 'https://affiliate.example.com/storage/post-images/later.jpg']);

        // An approved post must publish what you saw when you approved it.
        $this->assertNull($post->fresh()->imageUrl());
    }

    public function test_a_photo_with_a_link_caption_starts_a_new_post_instead_of_filling_the_waiting_one(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram();

        app(UpdateHandler::class)->handle(['update_id' => 10, 'message' => [
            'message_id' => 93, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'photo' => [['file_id' => 'big', 'file_size' => 2000]], 'caption' => 'TYESO https://s.shopee.com.my/new',
        ]]);

        $this->assertSame(PostStatus::Pending, $post->fresh()->status);
        $this->assertNull($post->fresh()->image_url);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/getFile'));
    }

    public function test_a_replaced_photo_is_deleted_from_the_server(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram();
        $this->replyWithPhoto(50, null);
        $first = basename($post->fresh()->image_url);

        $this->replyWithPhoto($post->fresh()->telegram_message_id, null);

        Storage::disk('public')->assertMissing('post-images/'.$first);
        Storage::disk('public')->assertExists('post-images/'.basename($post->fresh()->image_url));
    }

    public function test_the_rest_of_an_album_gets_no_hints(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram();

        // As Telegram sends it: only the first photo is a reply and carries the caption.
        foreach (['p1', 'p2', 'p3', 'p4'] as $i => $fileId) {
            app(UpdateHandler::class)->handle(['update_id' => 30 + $i, 'message' => array_filter([
                'message_id' => 110 + $i, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
                'media_group_id' => 'album-2', 'photo' => [['file_id' => $fileId, 'file_size' => 2000]],
                'caption' => $i === 0 ? "POST:\nPadu.\nREPLY:\nSini {link}" : null,
                'reply_to_message' => $i === 0 ? ['message_id' => 50] : null,
            ], fn ($value) => $value !== null)]);
        }

        $this->assertSame('Padu.', $post->fresh()->content);
        Http::assertNotSent(fn (Request $r) => str_contains((string) ($r->data()['text'] ?? ''), 'Send a Shopee or Amazon'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getFile'), 1);
    }

    public function test_only_the_first_photo_of_an_album_is_used(): void
    {
        [$post] = $this->waitingPost();
        $this->fakeTelegram();

        foreach (['p1', 'p2', 'p3'] as $i => $fileId) {
            app(UpdateHandler::class)->handle(['update_id' => 20 + $i, 'message' => [
                'message_id' => 100 + $i, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
                'media_group_id' => 'album-1', 'photo' => [['file_id' => $fileId, 'file_size' => 2000]],
                'reply_to_message' => ['message_id' => 50],
            ]]);
        }

        Http::assertSentCount(1 + 1 + 2); // one getFile, one download, the new message and the "continues below" edit
    }

    public function test_telegram_channel_posts_go_out_as_a_photo_with_the_text_as_caption(): void
    {
        $channel = SocialAccount::factory()->create(['platform' => SocialPlatform::TelegramChannel, 'external_id' => '@deals', 'access_token' => 'chan:tok']);
        Http::fake(['https://api.telegram.org/botchan:tok/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);

        app(SocialPublisherFactory::class)->make($channel)
            ->publish(new PostContent('Padu', 'https://affiliate.example.com/storage/post-images/a.jpg'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendPhoto')
            && $r['photo'] === 'https://affiliate.example.com/storage/post-images/a.jpg' && $r['caption'] === 'Padu');
    }

    public function test_the_caption_limit_counts_emoji_as_telegram_does(): void
    {
        $channel = SocialAccount::factory()->create(['platform' => SocialPlatform::TelegramChannel, 'external_id' => '@deals', 'access_token' => 'chan:tok']);
        Http::fake(['https://api.telegram.org/botchan:tok/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        // 1000 characters, but 1020 in Telegram's count.
        $text = str_repeat('a', 980).str_repeat('🔥', 20);

        app(SocialPublisherFactory::class)->make($channel)
            ->publish(new PostContent($text, 'https://affiliate.example.com/storage/post-images/a.jpg'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendPhoto') && $r['caption'] === $text);

        $longer = str_repeat('a', 1000).str_repeat('🔥', 20);
        app(SocialPublisherFactory::class)->make($channel)
            ->publish(new PostContent($longer, 'https://affiliate.example.com/storage/post-images/a.jpg'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['text'] === $longer);
    }

    public function test_a_failure_after_the_photo_went_out_is_not_retried(): void
    {
        $channel = SocialAccount::factory()->create(['platform' => SocialPlatform::TelegramChannel, 'external_id' => '@deals', 'access_token' => 'chan:tok']);
        Http::fake([
            'https://api.telegram.org/botchan:tok/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
            'https://api.telegram.org/botchan:tok/sendMessage' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests'], 429),
        ]);

        try {
            app(SocialPublisherFactory::class)->make($channel)
                ->publish(new PostContent(str_repeat('panjang ', 200), 'https://affiliate.example.com/storage/post-images/a.jpg'));
            $this->fail('Expected the failure to surface.');
        } catch (SocialApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('photo', $e->getMessage());
        }
    }

    public function test_long_telegram_channel_posts_send_the_photo_then_the_text(): void
    {
        $channel = SocialAccount::factory()->create(['platform' => SocialPlatform::TelegramChannel, 'external_id' => '@deals', 'access_token' => 'chan:tok']);
        Http::fake(['https://api.telegram.org/botchan:tok/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        $text = str_repeat('panjang ', 200);

        app(SocialPublisherFactory::class)->make($channel)
            ->publish(new PostContent($text, 'https://affiliate.example.com/storage/post-images/a.jpg'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendPhoto') && ! isset($r['caption']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['text'] === $text);
    }

    /**
     * @return array{Post, Product}
     */
    private function waitingPost(ContentSource $source = ContentSource::Manual): array
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create(['platform' => SocialPlatform::Threads, 'access_token' => 'tok', 'content_source' => $source]);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($affiliate)->create(['affiliate_link' => 'https://s.shopee.com.my/x', 'image_url' => null]);
        $post = app(DraftWriter::class)->createPending($product, $account, $campaign);
        $post->update(['telegram_chat_id' => '555', 'telegram_message_id' => 50]);

        return [$post->fresh(), $product];
    }

    private function fakeTelegram(int $fileSize = 2000, ?string $body = null): void
    {
        Http::fake([
            self::TG.'/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'big', 'file_path' => 'photos/file_1.jpg', 'file_size' => $fileSize]]),
            self::FILES.'/*' => Http::response($body ?? base64_decode(self::PNG)),
            self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 51]]),
        ]);
    }

    private function replyWithPhoto(int $toMessageId, ?string $caption): void
    {
        app(UpdateHandler::class)->handle(['update_id' => 3, 'message' => array_filter([
            'message_id' => 90, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'photo' => [['file_id' => 'small', 'file_size' => 100, 'width' => 90], ['file_id' => 'big', 'file_size' => 2000, 'width' => 1280]],
            'caption' => $caption,
            'reply_to_message' => ['message_id' => $toMessageId],
        ], fn ($value) => $value !== null)]);
    }
}
