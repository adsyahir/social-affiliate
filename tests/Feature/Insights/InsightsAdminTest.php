<?php

namespace Tests\Feature\Insights;

use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Widgets\PerformanceOverview;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InsightsAdminTest extends TestCase
{
    use RefreshDatabase;

    private const THREADS = 'https://graph.threads.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.threads.base_url' => self::THREADS]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_posts_list_shows_the_latest_numbers_without_a_query_per_row(): void
    {
        $post = $this->published();
        $post->insights()->create(['views' => 100, 'likes' => 5, 'replies' => 0, 'reposts' => 0, 'fetched_at' => now()->subDay()]);
        $post->insights()->create(['views' => 1520, 'likes' => 33, 'replies' => 4, 'reposts' => 2, 'fetched_at' => now()]);

        Livewire::test(ListPosts::class)
            ->assertTableColumnStateSet('latestInsight.views', 1520, $post)
            ->assertTableColumnStateSet('latestInsight.likes', 33, $post);

        DB::enableQueryLog();
        Livewire::test(ListPosts::class);
        $few = count(DB::getQueryLog());

        foreach (range(1, 5) as $i) {
            $this->published()->insights()->create(['views' => $i, 'likes' => 0, 'replies' => 0, 'reposts' => 0, 'fetched_at' => now()]);
        }
        DB::flushQueryLog();
        Livewire::test(ListPosts::class);

        $this->assertLessThanOrEqual($few, count(DB::getQueryLog()));
    }

    public function test_posts_cannot_be_created_by_hand(): void
    {
        // Posts come from "Generate draft" or the scheduler, so they always pass the posting rules.
        Livewire::test(ListPosts::class)->assertActionDoesNotExist('create');
    }

    public function test_refresh_insights_fetches_a_new_snapshot(): void
    {
        $post = $this->published();
        Http::fake([self::THREADS.'/*' => Http::response(['data' => [['name' => 'views', 'values' => [['value' => 42]]]]])]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSeeInOrder(['Performance', 'Views', 'Not fetched yet'])
            ->callAction('refreshInsights')
            ->assertNotified('Insights updated')
            // The section itself must show the new numbers, not only the notification.
            ->assertSeeInOrder(['Performance', 'Views', '42', 'Likes'])
            ->assertDontSee('Not fetched yet');

        $this->assertSame(42, $post->insights()->sole()->views);
    }

    public function test_refresh_insights_is_only_for_published_posts(): void
    {
        $draft = Post::factory()->create(['link_reply_content' => 'x']);

        Livewire::test(EditPost::class, ['record' => $draft->getRouteKey()])->assertActionHidden('refreshInsights');
    }

    public function test_dashboard_shows_the_last_seven_days(): void
    {
        $this->travelTo(now());
        $recent = $this->published(daysAgo: 2);
        $recent->insights()->create(['views' => 100, 'likes' => 1, 'replies' => 0, 'reposts' => 0, 'fetched_at' => now()->subDay()]);
        $recent->insights()->create(['views' => 1500, 'likes' => 30, 'replies' => 0, 'reposts' => 0, 'fetched_at' => now()]);
        $other = $this->published(daysAgo: 3);
        $other->insights()->create(['views' => 20, 'likes' => 3, 'replies' => 0, 'reposts' => 0, 'fetched_at' => now()]);
        $old = $this->published(daysAgo: 20);
        $old->insights()->create(['views' => 99999, 'likes' => 999, 'replies' => 0, 'reposts' => 0, 'fetched_at' => now()]);
        Post::factory()->count(2)->create(['link_reply_content' => 'x', 'status' => PostStatus::Draft]);

        Livewire::test(PerformanceOverview::class)
            ->assertSee('Waiting for approval')
            ->assertSeeInOrder(['Published', '2'])
            // Latest snapshot per post only: 1,500 + 20, not 1,620 and not the old post.
            ->assertSee('1,520')
            ->assertSee('33')
            ->assertDontSee('99,999');
    }

    private function published(int $daysAgo = 1): Post
    {
        $account = SocialAccount::factory()->create(['platform' => SocialPlatform::Threads, 'external_id' => '1789', 'access_token' => 'tok']);

        return Post::factory()->published()->create([
            'social_account_id' => $account->id,
            'external_post_id' => 'media-1',
            'published_at' => now()->subDays($daysAgo),
            'link_reply_content' => 'x',
        ]);
    }
}
