<?php

namespace Tests\Feature\Social;

use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Jobs\PublishPost;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Social\Contracts\SocialPublisher;
use App\Social\Data\PostContent;
use App\Social\Exceptions\SocialApiException;
use App\Social\SocialPublisherFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Posts with several photos: a carousel on Threads, an album in a Telegram channel.
 */
class CarouselTest extends TestCase
{
    use RefreshDatabase;

    private const PHOTOS = [
        'https://affiliate.example.com/storage/post-images/1.jpg',
        'https://affiliate.example.com/storage/post-images/2.jpg',
        'https://affiliate.example.com/storage/post-images/3.jpg',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.threads.base_url' => 'https://graph.threads.net',
            'services.threads.status_check_seconds' => 0,
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.api_base_url' => 'https://api.telegram.org',
        ]);
        Http::preventStrayRequests();
    }

    public function test_several_photos_become_a_threads_carousel(): void
    {
        $items = 0;
        Http::fake(function (Request $request) use (&$items) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1.0/me/threads_publish' => Http::response(['id' => 'media-1']),
                $path === '/v1.0/me/threads' && ($request['is_carousel_item'] ?? null) => Http::response(['id' => 'item-'.(++$items)]),
                $path === '/v1.0/me/threads' => Http::response(['id' => 'carousel-1']),
                str_starts_with((string) $path, '/v1.0/carousel-1') || str_starts_with((string) $path, '/v1.0/item-') => Http::response(['status' => 'FINISHED']),
                default => Http::response(['permalink' => 'https://www.threads.com/@a/post/x']),
            };
        });

        $this->threads()->publish(new PostContent('Padu', imageUrls: self::PHOTOS));

        foreach (self::PHOTOS as $photo) {
            Http::assertSent(fn (Request $r) => ($r['is_carousel_item'] ?? null) === true && $r['media_type'] === 'IMAGE' && $r['image_url'] === $photo && ! isset($r['text']));
        }
        Http::assertSent(fn (Request $r) => ($r['media_type'] ?? null) === 'CAROUSEL' && $r['children'] === 'item-1,item-2,item-3' && $r['text'] === 'Padu');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads_publish') && $r['creation_id'] === 'carousel-1');
    }

    public function test_one_photo_is_still_a_plain_image_post(): void
    {
        Http::fake([
            'graph.threads.net/v1.0/me/threads_publish*' => Http::response(['id' => 'media-1']),
            'graph.threads.net/v1.0/me/threads*' => Http::response(['id' => 'c-1']),
            'graph.threads.net/v1.0/*' => Http::response(['status' => 'FINISHED', 'permalink' => 'https://www.threads.com/@a/post/x']),
        ]);

        $this->threads()->publish(new PostContent('Padu', imageUrls: [self::PHOTOS[0]]));

        Http::assertSent(fn (Request $r) => ($r['media_type'] ?? null) === 'IMAGE' && $r['image_url'] === self::PHOTOS[0] && ! isset($r['is_carousel_item']));
    }

    public function test_several_photos_become_a_telegram_channel_album_with_the_text_as_caption(): void
    {
        Http::fake(['https://api.telegram.org/botchan:tok/*' => Http::response(['ok' => true, 'result' => [['message_id' => 7], ['message_id' => 8], ['message_id' => 9]]])]);

        $published = $this->channel()->publish(new PostContent('Padu', imageUrls: self::PHOTOS));

        $this->assertSame('7', $published->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMediaGroup')
            && $r['media'] === [
                ['type' => 'photo', 'media' => self::PHOTOS[0], 'caption' => 'Padu'],
                ['type' => 'photo', 'media' => self::PHOTOS[1]],
                ['type' => 'photo', 'media' => self::PHOTOS[2]],
            ]);
    }

    public function test_a_long_text_follows_the_album_and_a_failure_then_is_not_retried(): void
    {
        Http::fake([
            'https://api.telegram.org/botchan:tok/sendMediaGroup' => Http::response(['ok' => true, 'result' => [['message_id' => 7], ['message_id' => 8]]]),
            'https://api.telegram.org/botchan:tok/sendMessage' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500),
        ]);

        try {
            $this->channel()->publish(new PostContent(str_repeat('panjang ', 200), imageUrls: self::PHOTOS));
            $this->fail('Expected the failure to surface.');
        } catch (SocialApiException $e) {
            $this->assertFalse($e->retryable);
        }

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMediaGroup') && ! isset($r['media'][0]['caption']));
    }

    public function test_publishing_sends_all_the_posts_photos_and_facebook_gets_the_first(): void
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create(['platform' => SocialPlatform::FacebookPage, 'external_id' => '4242', 'access_token' => 'page']);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $post = Post::factory()->create([
            'campaign_id' => $campaign->id, 'product_id' => Product::factory()->for($affiliate)->create()->id, 'social_account_id' => $account->id,
            'status' => PostStatus::Approved, 'content' => 'Padu', 'link_reply_content' => 'Sini',
            'image_url' => self::PHOTOS[0], 'images' => self::PHOTOS,
        ]);
        config(['services.facebook' => ['base_url' => 'https://graph.facebook.com', 'version' => 'v25.0']]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '4242_1', 'post_id' => '4242_1']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        PublishPost::dispatchSync($post);

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/4242/photos') && $r['url'] === self::PHOTOS[0]);
        $this->assertSame(self::PHOTOS, $post->fresh()->imageUrls());
    }

    private function threads(): SocialPublisher
    {
        return app(SocialPublisherFactory::class)->make(SocialAccount::factory()->create(['platform' => SocialPlatform::Threads, 'external_id' => '1789', 'access_token' => 'tok']));
    }

    private function channel(): SocialPublisher
    {
        return app(SocialPublisherFactory::class)->make(SocialAccount::factory()->create(['platform' => SocialPlatform::TelegramChannel, 'external_id' => '@deals', 'access_token' => 'chan:tok']));
    }
}
