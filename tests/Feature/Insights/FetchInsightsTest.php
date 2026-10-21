<?php

namespace Tests\Feature\Insights;

use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Jobs\FetchPostInsights;
use App\Models\Post;
use App\Models\PostInsight;
use App\Models\SocialAccount;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const THREADS = 'https://graph.threads.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.threads.base_url' => self::THREADS, 'services.insights.days' => 30]);
        Http::preventStrayRequests();
    }

    public function test_recent_published_posts_are_queued(): void
    {
        Queue::fake();
        $recent = $this->published(daysAgo: 2);
        $edge = $this->published(daysAgo: 29);
        $this->published(daysAgo: 45);
        $this->published(daysAgo: 1, attributes: ['status' => PostStatus::Draft, 'published_at' => null]);
        $this->published(daysAgo: 1, attributes: ['external_post_id' => null]);
        $this->published(daysAgo: 1, account: SocialAccount::factory()->create(['platform' => SocialPlatform::X, 'access_token' => 'x']));

        $this->artisan('insights:fetch')->expectsOutputToContain('Queued insights for 2 posts')->assertSuccessful();

        Queue::assertPushed(FetchPostInsights::class, 2);
        Queue::assertPushed(FetchPostInsights::class, fn (FetchPostInsights $job) => $job->post->is($recent));
        Queue::assertPushed(FetchPostInsights::class, fn (FetchPostInsights $job) => $job->post->is($edge));
    }

    public function test_the_summary_reads_naturally_for_one_post(): void
    {
        Queue::fake();
        $this->published();

        $this->artisan('insights:fetch')->expectsOutputToContain('Queued insights for 1 post published')->assertSuccessful();
    }

    public function test_the_job_saves_a_snapshot(): void
    {
        $this->freezeSecond();
        $post = $this->published();
        Http::fake([self::THREADS.'/v1.0/media-1/insights*' => Http::response(['data' => [
            ['name' => 'views', 'values' => [['value' => 1520]]],
            ['name' => 'likes', 'values' => [['value' => 33]]],
            ['name' => 'replies', 'values' => [['value' => 4]]],
            ['name' => 'reposts', 'values' => [['value' => 2]]],
        ]])]);

        FetchPostInsights::dispatchSync($post);

        $snapshot = PostInsight::sole();
        $this->assertSame([1520, 33, 4, 2], [$snapshot->views, $snapshot->likes, $snapshot->replies, $snapshot->reposts]);
        $this->assertTrue($snapshot->fetched_at->equalTo(now()));
        $this->assertSame(1520, $post->fresh()->latestInsight->views);
    }

    public function test_a_rejected_token_is_logged_and_not_retried(): void
    {
        Log::spy();
        $post = $this->published();
        Http::fake([self::THREADS.'/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)]);

        FetchPostInsights::dispatchSync($post);

        $this->assertSame(0, PostInsight::count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Insights not fetched'
            && $context['post_id'] === $post->id && str_contains($context['error'], 'Session has expired'));
    }

    public function test_the_fetch_is_scheduled_daily_in_the_campaign_timezone(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'insights:fetch'));

        $this->assertNotNull($event);
        $this->assertSame('0 6 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function published(int $daysAgo = 1, ?SocialAccount $account = null, array $attributes = []): Post
    {
        $account ??= SocialAccount::factory()->create(['platform' => SocialPlatform::Threads, 'external_id' => '1789', 'access_token' => 'tok']);

        return Post::factory()->published()->create([
            'social_account_id' => $account->id,
            'external_post_id' => 'media-1',
            'published_at' => now()->subDays($daysAgo),
            ...$attributes,
        ]);
    }
}
