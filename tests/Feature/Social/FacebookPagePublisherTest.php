<?php

namespace Tests\Feature\Social;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Social\Data\PostContent;
use App\Social\Exceptions\AccessTokenException;
use App\Social\Exceptions\InvalidContentException;
use App\Social\Exceptions\SocialApiException;
use App\Social\Facebook\FacebookPagePublisher;
use App\Social\SocialPublisherFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookPagePublisherTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://graph.facebook.com/v25.0';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.facebook' => ['base_url' => 'https://graph.facebook.com', 'version' => 'v25.0']]);
        Http::preventStrayRequests();
    }

    public function test_text_posts_go_to_the_page_feed(): void
    {
        Http::fake([
            self::API.'/4242/feed' => Http::response(['id' => '4242_777']),
            self::API.'/4242_777*' => Http::response(['id' => '4242_777', 'permalink_url' => 'https://www.facebook.com/4242/posts/777']),
        ]);

        $post = $this->publisher()->publish(new PostContent('Barang murah tapi best'));

        $this->assertSame('4242_777', $post->id);
        $this->assertSame('https://www.facebook.com/4242/posts/777', $post->permalink);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/4242/feed' && $r['message'] === 'Barang murah tapi best' && $r['access_token'] === 'page-token');
    }

    public function test_image_posts_go_to_photos_with_a_caption(): void
    {
        Http::fake([
            self::API.'/4242/photos' => Http::response(['id' => 'photo-1', 'post_id' => '4242_778']),
            self::API.'/4242_778*' => Http::response(['permalink_url' => 'https://www.facebook.com/4242/posts/778']),
        ]);

        $post = $this->publisher()->publish(new PostContent('Look', imageUrl: 'https://cdn.example.com/lamp.jpg'));

        $this->assertSame('4242_778', $post->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/4242/photos') && $r['url'] === 'https://cdn.example.com/lamp.jpg' && $r['caption'] === 'Look');
    }

    public function test_replies_are_comments_on_the_post(): void
    {
        Http::fake([self::API.'/4242_777/comments' => Http::response(['id' => '777_c1'])]);

        $reply = $this->publisher()->reply('4242_777', new PostContent("Link: https://amzn.to/x\n\n#ad"));

        $this->assertSame('777_c1', $reply->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/4242_777/comments') && str_contains($r['message'], 'amzn.to'));
    }

    public function test_insights_come_from_reactions_comments_shares_and_media_views(): void
    {
        Http::fake([
            self::API.'/4242_777/insights*' => Http::response(['data' => [['name' => 'post_media_view', 'values' => [['value' => 880]]]]]),
            self::API.'/4242_777*' => Http::response([
                'reactions' => ['data' => [], 'summary' => ['total_count' => 41]],
                'comments' => ['data' => [], 'summary' => ['total_count' => 6]],
                'shares' => ['count' => 3],
            ]),
        ]);

        $metrics = $this->publisher()->getInsights('4242_777');

        $this->assertSame(['views' => 880, 'likes' => 41, 'replies' => 6, 'reposts' => 3], $metrics->toArray());
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'fields=reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares'));
    }

    public function test_views_are_optional_when_meta_rejects_the_metric(): void
    {
        Http::fake([
            self::API.'/4242_777/insights*' => Http::response(['error' => ['message' => '(#100) The value must be a valid insights metric', 'code' => 100]], 400),
            self::API.'/4242_777*' => Http::response(['reactions' => ['summary' => ['total_count' => 2]], 'comments' => ['summary' => ['total_count' => 0]]]),
        ]);

        $metrics = $this->publisher()->getInsights('4242_777');

        $this->assertSame(['views' => 0, 'likes' => 2, 'replies' => 0, 'reposts' => 0], $metrics->toArray());
    }

    public function test_verify_returns_the_page(): void
    {
        Http::fake([self::API.'/me*' => Http::response(['id' => '4242', 'name' => 'Barang Murah MY'])]);

        $profile = $this->publisher()->verify();

        $this->assertSame('4242', $profile->id);
        $this->assertSame('Barang Murah MY', $profile->username);
        // A Page name is not an @handle.
        $this->assertFalse($profile->isHandle);
    }

    public function test_errors_follow_the_graph_api_rules(): void
    {
        Http::fake([self::API.'/*' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)]);

        try {
            $this->publisher()->verify();
            $this->fail('Expected an exception.');
        } catch (AccessTokenException $e) {
            $this->assertStringContainsString('Facebook rejected the access token', $e->getMessage());
        }
    }

    public function test_a_lost_connection_while_posting_is_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        try {
            $this->publisher()->publish(new PostContent('Hello'));
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('may have been published', $e->getMessage());
        }
    }

    public function test_content_limits_are_checked_first(): void
    {
        Http::fake();

        $this->expectException(InvalidContentException::class);

        try {
            $this->publisher()->publish(new PostContent(str_repeat('a', 5001)));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_factory_resolves_facebook_pages_with_insights(): void
    {
        $this->assertInstanceOf(FacebookPagePublisher::class, $this->publisher());
        $this->assertTrue(SocialPublisherFactory::supportsInsights(SocialPlatform::FacebookPage));
    }

    private function publisher(): FacebookPagePublisher
    {
        $account = SocialAccount::factory()->create([
            'platform' => SocialPlatform::FacebookPage,
            'external_id' => '4242',
            'access_token' => 'page-token',
        ]);

        return app(SocialPublisherFactory::class)->make($account);
    }
}
