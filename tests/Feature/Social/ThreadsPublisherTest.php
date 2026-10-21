<?php

namespace Tests\Feature\Social;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Social\Data\PostContent;
use App\Social\Exceptions\AccessTokenException;
use App\Social\Exceptions\InvalidContentException;
use App\Social\Exceptions\MissingTokenException;
use App\Social\Exceptions\SocialApiException;
use App\Social\Exceptions\UnsupportedPlatformException;
use App\Social\SocialPublisherFactory;
use App\Social\Threads\ThreadsPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ThreadsPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://graph.threads.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.threads.base_url' => self::BASE]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_text_post_is_created_checked_and_published(): void
    {
        $this->fakeThreads();

        $post = $this->publisher()->publish(new PostContent('Best earbuds under $50 right now?'));

        $this->assertSame('media-1', $post->id);
        $this->assertSame('https://www.threads.com/@gadgets/post/abc', $post->permalink);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/v1.0/1789/threads'
            && $r['media_type'] === 'TEXT'
            && $r['text'] === 'Best earbuds under $50 right now?'
            && $r['access_token'] === 'token-abc'
            && ! isset($r['reply_to_id']));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/v1.0/1789/threads_publish'
            && $r['creation_id'] === 'container-1');
        Sleep::assertNeverSlept();
    }

    public function test_reply_targets_the_parent_post(): void
    {
        $this->fakeThreads();

        $this->publisher()->reply('media-1', new PostContent('Link: https://s.shopee.com.my/abc (affiliate link)'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/1789/threads')
            && $r['reply_to_id'] === 'media-1');
    }

    public function test_image_post_sends_the_image_url(): void
    {
        $this->fakeThreads();

        $this->publisher()->publish(new PostContent('Look at this lamp', imageUrl: 'https://cdn.example.com/lamp.jpg'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1789/threads')
            && $r['media_type'] === 'IMAGE'
            && $r['image_url'] === 'https://cdn.example.com/lamp.jpg');
    }

    public function test_publishing_waits_until_the_container_is_ready(): void
    {
        $this->fakeThreads(statuses: ['IN_PROGRESS', 'IN_PROGRESS', 'FINISHED']);

        $this->publisher()->publish(new PostContent('Hello'));

        Sleep::assertSleptTimes(2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads_publish'));
    }

    public function test_container_that_never_gets_ready_is_retryable(): void
    {
        config(['services.threads.status_checks' => 3]);
        $this->fakeThreads(statuses: ['IN_PROGRESS', 'IN_PROGRESS', 'IN_PROGRESS']);

        try {
            $this->publisher()->publish(new PostContent('Hello'));
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            $this->assertTrue($e->retryable);
        }

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/threads_publish'));
    }

    public function test_failed_container_is_not_retryable(): void
    {
        $this->fakeThreads(statuses: ['ERROR']);

        try {
            $this->publisher()->publish(new PostContent('Hello', imageUrl: 'https://cdn.example.com/x.jpg'));
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('UNKNOWN', $e->getMessage());
        }
    }

    public function test_content_over_the_limits_is_rejected_before_calling_the_api(): void
    {
        Http::fake();
        $publisher = $this->publisher();

        foreach ([
            str_repeat('a', 501),
            // Threads counts an emoji as its UTF-8 bytes: 125 four-byte emoji are 500, one more is over.
            str_repeat('😀', 126),
            'Links: https://a.co https://b.co https://c.co https://d.co https://e.co https://f.co',
            '   ',
        ] as $text) {
            try {
                $publisher->publish(new PostContent($text));
                $this->fail('Expected rejection for: '.mb_substr($text, 0, 20));
            } catch (InvalidContentException) {
                // Expected.
            }
        }

        $this->assertSame(500, $publisher->maxLength());
        Http::assertNothingSent();
    }

    public function test_emoji_up_to_the_byte_limit_are_allowed(): void
    {
        $this->fakeThreads();

        $this->publisher()->publish(new PostContent(str_repeat('😀', 125)));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads_publish'));
    }

    public function test_insights_are_mapped_and_missing_metrics_are_zero(): void
    {
        Http::fake([self::BASE.'/v1.0/media-1/insights*' => Http::response(['data' => [
            ['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 1520]]],
            ['name' => 'likes', 'period' => 'lifetime', 'values' => [['value' => 33]]],
            ['name' => 'replies', 'period' => 'lifetime', 'values' => [['value' => 4]]],
        ]])]);

        $metrics = $this->publisher()->getInsights('media-1');

        $this->assertSame(['views' => 1520, 'likes' => 33, 'replies' => 4, 'reposts' => 0], $metrics->toArray());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'metric=views%2Clikes%2Creplies%2Creposts'));
    }

    public function test_verify_returns_the_connected_profile(): void
    {
        Http::fake([self::BASE.'/v1.0/me*' => Http::response(['id' => '1789', 'username' => 'gadgets'])]);

        $profile = $this->publisher()->verify();

        $this->assertSame('1789', $profile->id);
        $this->assertSame('gadgets', $profile->username);
    }

    public function test_user_id_is_looked_up_when_the_account_has_none(): void
    {
        $this->fakeThreads();

        $this->publisher(['external_id' => null])->publish(new PostContent('Hello'));

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/v1.0/1789/threads_publish');
    }

    public function test_api_errors_are_classified(): void
    {
        $cases = [
            'expired token' => [['error' => ['message' => 'Session has expired', 'type' => 'OAuthException', 'code' => 190]], 400, AccessTokenException::class, false],
            'rate limited' => [['error' => ['message' => 'Application request limit reached', 'code' => 4]], 400, SocialApiException::class, true],
            'transient' => [['error' => ['message' => 'Temporary issue', 'code' => 2, 'is_transient' => true]], 500, SocialApiException::class, true],
            'bad request' => [['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400, SocialApiException::class, false],
            'server error' => ['<html>Bad gateway</html>', 502, SocialApiException::class, true],
        ];

        $responses = Http::sequence();
        foreach ($cases as [$body, $status]) {
            $responses->push($body, $status);
        }
        Http::fake([self::BASE.'/*' => $responses]);

        foreach ($cases as $name => [, , $class, $retryable]) {
            try {
                $this->publisher()->verify();
                $this->fail("Expected an exception for {$name}.");
            } catch (SocialApiException $e) {
                $this->assertInstanceOf($class, $e, $name);
                $this->assertSame($retryable, $e->retryable, $name);
                $this->assertStringNotContainsString('token-abc', $e->getMessage(), $name);
            }
        }
    }

    public function test_unreachable_api_is_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        try {
            $this->publisher()->verify();
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_the_token_never_reaches_logs_or_exceptions_when_the_connection_fails(): void
    {
        Log::spy();
        Http::fake(fn (Request $r) => throw new ConnectionException("cURL error 28: timed out for {$r->url()}"));

        try {
            $this->publisher()->verify();
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            for ($error = $e; $error !== null; $error = $error->getPrevious()) {
                $this->assertStringNotContainsString('token-abc', $error->getMessage());
            }
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), 'token-abc'));
    }

    public function test_a_lost_connection_while_publishing_is_not_retried(): void
    {
        // If the publish call times out, the post may already be live; retrying could post it twice.
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/threads_publish') => throw new ConnectionException('timed out'),
                str_ends_with($path, '/threads') => Http::response(['id' => 'container-1']),
                default => Http::response(['status' => 'FINISHED']),
            };
        });

        try {
            $this->publisher()->publish(new PostContent('Hello'));
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('may have been published', $e->getMessage());
        }
    }

    public function test_status_checks_fit_well_inside_a_web_request(): void
    {
        $seconds = config('services.threads.status_checks') * config('services.threads.status_check_seconds');

        $this->assertLessThanOrEqual(15, $seconds);
    }

    public function test_account_without_token_cannot_publish(): void
    {
        Http::fake();

        $this->expectException(MissingTokenException::class);

        $this->publisher(['access_token' => null])->publish(new PostContent('Hello'));
    }

    public function test_long_lived_token_is_refreshed(): void
    {
        $this->freezeSecond();
        Http::fake([self::BASE.'/refresh_access_token*' => Http::response(['access_token' => 'token-new', 'token_type' => 'bearer', 'expires_in' => 5184000])]);

        $token = $this->publisher()->refreshAccessToken();

        $this->assertSame('token-new', $token->token);
        $this->assertTrue($token->expiresAt->equalTo(now()->addSeconds(5184000)));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/refresh_access_token?')
            && $r['grant_type'] === 'th_refresh_token'
            && $r['access_token'] === 'token-abc');
    }

    public function test_factory_resolves_drivers_by_platform(): void
    {
        $factory = app(SocialPublisherFactory::class);

        $this->assertInstanceOf(ThreadsPublisher::class, $factory->make(SocialAccount::factory()->create()));
        $this->assertTrue(SocialPublisherFactory::supports(SocialPlatform::Threads));
        $this->assertFalse(SocialPublisherFactory::supports(SocialPlatform::X));

        $this->expectException(UnsupportedPlatformException::class);
        $factory->make(SocialAccount::factory()->create(['platform' => SocialPlatform::X]));
    }

    /**
     * Fakes the container, status, publish, permalink and profile endpoints.
     *
     * @param  list<string>  $statuses  Successive container statuses to return.
     */
    private function fakeThreads(array $statuses = ['FINISHED']): void
    {
        $statusQueue = $statuses;

        Http::fake(function (Request $request) use (&$statusQueue) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'POST' && str_ends_with($path, '/threads') => Http::response(['id' => 'container-1']),
                $request->method() === 'POST' && str_ends_with($path, '/threads_publish') => Http::response(['id' => 'media-1']),
                $path === '/v1.0/container-1' => Http::response(['id' => 'container-1', 'status' => array_shift($statusQueue) ?? 'FINISHED', 'error_message' => 'UNKNOWN']),
                $path === '/v1.0/media-1' => Http::response(['id' => 'media-1', 'permalink' => 'https://www.threads.com/@gadgets/post/abc']),
                $path === '/v1.0/me' => Http::response(['id' => '1789', 'username' => 'gadgets']),
                default => Http::response(['error' => ['message' => "Unexpected {$path}", 'code' => 100]], 400),
            };
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publisher(array $attributes = []): ThreadsPublisher
    {
        $account = SocialAccount::factory()->create([
            'platform' => SocialPlatform::Threads,
            'external_id' => '1789',
            'access_token' => 'token-abc',
            ...$attributes,
        ]);

        return app(SocialPublisherFactory::class)->make($account);
    }
}
