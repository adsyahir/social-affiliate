<?php

namespace Tests\Feature\Campaigns;

use App\Campaigns\CampaignRunner;
use App\Campaigns\PostingSchedule;
use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Enums\ProductStatus;
use App\Jobs\GenerateDraft;
use App\Jobs\SyncApprovalMessage;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class CampaignRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.campaigns' => ['timezone' => 'Asia/Kuala_Lumpur', 'window_start' => '08:00', 'window_end' => '22:00'],
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.api_base_url' => 'https://api.telegram.org',
        ]);
        Http::preventStrayRequests();
    }

    public function test_slots_are_spread_evenly_across_the_window(): void
    {
        $day = CarbonImmutable::parse('2026-10-21', 'Asia/Kuala_Lumpur');

        $slots = array_map(fn ($slot) => $slot->format('H:i'), app(PostingSchedule::class)->slots(3, $day));
        $this->assertSame(['08:00', '12:40', '17:20'], $slots);

        $this->assertSame(['08:00'], array_map(fn ($slot) => $slot->format('H:i'), app(PostingSchedule::class)->slots(1, $day)));
        $this->assertSame([], app(PostingSchedule::class)->slots(0, $day));
    }

    public function test_each_due_slot_writes_one_batch_for_every_account_in_the_campaign(): void
    {
        Queue::fake();
        [$campaign, $accounts, $products] = $this->campaign(postsPerDay: 3, accounts: 2, products: 3);

        $this->at('08:05', fn () => app(CampaignRunner::class)->runDue());
        $this->at('08:20', fn () => app(CampaignRunner::class)->runDue());

        Queue::assertPushed(GenerateDraft::class, 2);
        foreach ($accounts as $account) {
            Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->account->is($account) && $job->product->is($products[0]) && $job->campaign->is($campaign));
        }

        $this->at('12:45', fn () => app(CampaignRunner::class)->runDue());

        Queue::assertPushed(GenerateDraft::class, 4);
        Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->product->is($products[1]));
        $this->assertSame(2, $campaign->runs()->where('status', CampaignRun::DISPATCHED)->count());
    }

    public function test_nothing_runs_outside_the_window(): void
    {
        Queue::fake();
        $this->campaign(postsPerDay: 3);

        $this->at('07:30', fn () => app(CampaignRunner::class)->runDue());
        $this->at('22:30', fn () => app(CampaignRunner::class)->runDue());

        Queue::assertNothingPushed();
    }

    public function test_missed_slots_are_caught_up_one_batch_per_run(): void
    {
        Queue::fake();
        [$campaign] = $this->campaign(postsPerDay: 3, accounts: 1, products: 5);

        // The server was down all morning: three slots are due, but only one batch goes out per run.
        $this->at('18:00', fn () => app(CampaignRunner::class)->runDue());
        $this->assertSame(1, $campaign->runs()->count());

        $this->at('18:15', fn () => app(CampaignRunner::class)->runDue());
        $this->at('18:30', fn () => app(CampaignRunner::class)->runDue());
        $this->at('18:45', fn () => app(CampaignRunner::class)->runDue());
        $this->assertSame(3, $campaign->runs()->count());
    }

    public function test_inactive_or_empty_campaigns_are_skipped(): void
    {
        Queue::fake();
        $this->campaign(active: false);
        $this->campaign(accounts: 0);

        $this->at('09:00', fn () => app(CampaignRunner::class)->runDue());

        Queue::assertNothingPushed();
        $this->assertSame(0, CampaignRun::count());
    }

    public function test_products_already_drafted_used_or_archived_are_skipped(): void
    {
        Queue::fake();
        [$campaign, $accounts, $products] = $this->campaign(products: 4);
        Post::factory()->create(['campaign_id' => $campaign->id, 'product_id' => $products[0]->id, 'social_account_id' => $accounts[0]->id]);
        $products[1]->update(['status' => ProductStatus::Used]);
        $products[2]->update(['status' => ProductStatus::Archived]);

        $this->at('09:00', fn () => app(CampaignRunner::class)->runDue());

        Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->product->is($products[3]));
    }

    public function test_running_out_of_products_is_reported_once_a_day(): void
    {
        Queue::fake();
        [$campaign] = $this->campaign(postsPerDay: 3, products: 0);
        Http::fake([self::TG.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->at('08:05', fn () => app(CampaignRunner::class)->runDue());
        $this->at('12:45', fn () => app(CampaignRunner::class)->runDue());

        Queue::assertNothingPushed();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], $campaign->name) && str_contains($r['text'], 'no products left'));
    }

    public function test_manual_runs_do_not_use_up_the_days_slots(): void
    {
        Queue::fake();
        [$campaign] = $this->campaign(postsPerDay: 1, products: 3);

        $this->at('07:00', fn () => app(CampaignRunner::class)->run($campaign, CampaignRun::MANUAL));
        $this->at('08:05', fn () => app(CampaignRunner::class)->runDue());

        $this->assertSame(1, $campaign->runs()->where('trigger', CampaignRun::MANUAL)->count());
        $this->assertSame(1, $campaign->runs()->where('trigger', CampaignRun::SCHEDULE)->count());
        Queue::assertPushed(GenerateDraft::class, 2);
    }

    public function test_a_failed_draft_frees_the_product_but_still_uses_the_slot(): void
    {
        [$campaign, $accounts, $products] = $this->campaign(postsPerDay: 2, products: 2);
        Http::fake([self::TG.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->mock(DraftWriter::class, fn (MockInterface $mock) => $mock->shouldReceive('create')
            ->andThrow(new ContentGenerationException('Set ANTHROPIC_API_KEY in .env')));

        // The queue is sync in tests, so the job runs (and fails) inside runDue().
        $this->at('08:05', fn () => app(CampaignRunner::class)->runDue());
        $this->at('08:20', fn () => app(CampaignRunner::class)->runDue());

        $this->assertSame(CampaignRun::FAILED, $campaign->runs()->sole()->status);
        Http::assertSentCount(1);

        // At the next slot the same product is tried again instead of being skipped for good.
        Queue::fake();
        $this->at('15:05', fn () => app(CampaignRunner::class)->runDue());
        Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->product->is($products[0]));
    }

    public function test_the_runner_is_scheduled_every_fifteen_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'campaigns:run'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_generate_draft_writes_the_draft_and_sends_it_for_approval(): void
    {
        Queue::fake();
        [$campaign, $accounts, $products] = $this->campaign();
        $post = Post::factory()->make(['id' => 99]);
        $this->mock(DraftWriter::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->once()
            ->withArgs(fn ($product, $account, $c) => $product->is($products[0]) && $account->is($accounts[0]) && $c->is($campaign))
            ->andReturn($post));

        (new GenerateDraft($accounts[0], $products[0], $campaign))->handle(app(DraftWriter::class));

        Queue::assertPushed(SyncApprovalMessage::class);
    }

    public function test_scheduled_times_follow_the_campaign_timezone(): void
    {
        $refresh = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'social:refresh-tokens'));

        $this->assertSame('Asia/Kuala_Lumpur', $refresh->timezone);
    }

    public function test_generate_draft_reports_permanent_failures_in_telegram(): void
    {
        [$campaign, $accounts, $products] = $this->campaign();
        Http::fake([self::TG.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->mock(DraftWriter::class, fn (MockInterface $mock) => $mock->shouldReceive('create')
            ->andThrow(new ContentGenerationException('Set ANTHROPIC_API_KEY in .env')));

        GenerateDraft::dispatchSync($accounts[0], $products[0], $campaign);

        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Could not write a draft')
            && str_contains($r['text'], 'ANTHROPIC_API_KEY')
            && str_contains($r['text'], $accounts[0]->name));
    }

    /**
     * Run a callback at a wall-clock time in Malaysia on 21 October 2026.
     */
    private function at(string $time, callable $callback): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-10-21 {$time}", 'Asia/Kuala_Lumpur'));
        $callback();
    }

    /**
     * @return array{Campaign, list<SocialAccount>, list<Product>}
     */
    private function campaign(int $postsPerDay = 1, int $accounts = 1, int $products = 2, bool $active = true): array
    {
        $affiliate = AffiliateAccount::factory()->create();
        $campaign = Campaign::factory()->for($affiliate)->create(['posts_per_day' => $postsPerDay, 'active' => $active]);
        $socialAccounts = SocialAccount::factory()->count($accounts)->create()->all();
        $campaign->socialAccounts()->attach(array_map(fn ($a) => $a->id, $socialAccounts));
        $productList = [];
        for ($i = 0; $i < $products; $i++) {
            $productList[] = Product::factory()->for($affiliate)->create();
        }

        return [$campaign, $socialAccounts, $productList];
    }
}
