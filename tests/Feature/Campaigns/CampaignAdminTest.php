<?php

namespace Tests\Feature\Campaigns;

use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Jobs\GenerateDraft;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.campaigns' => ['timezone' => 'Asia/Kuala_Lumpur', 'window_start' => '08:00', 'window_end' => '22:00']]);
        config(['services.telegram.bot_token' => null]);
        $this->actingAs(User::factory()->create());
    }

    public function test_run_now_starts_a_batch(): void
    {
        Queue::fake();
        $campaign = $this->campaign(products: 1);

        Livewire::test(ListCampaigns::class)
            ->callTableAction('runNow', $campaign)
            ->assertNotified('Started');

        Queue::assertPushed(GenerateDraft::class);
        $this->assertSame(CampaignRun::MANUAL, $campaign->runs()->sole()->trigger);
    }

    public function test_run_now_explains_why_nothing_happened(): void
    {
        Queue::fake();

        Livewire::test(ListCampaigns::class)
            ->callTableAction('runNow', $this->campaign(products: 0))
            ->assertNotified('No products left');

        Livewire::test(ListCampaigns::class)
            ->callTableAction('runNow', $this->campaign(products: 1, accounts: 0))
            ->assertNotified('No social accounts');

        Queue::assertNothingPushed();
    }

    public function test_table_shows_the_last_run_and_next_slot_without_a_query_per_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00', 'Asia/Kuala_Lumpur'));
        $campaigns = collect(range(1, 5))->map(fn () => $this->campaign(products: 1, postsPerDay: 3));
        $campaigns->first()->runs()->create(['status' => CampaignRun::DISPATCHED, 'trigger' => CampaignRun::SCHEDULE, 'accounts' => 1]);

        DB::enableQueryLog();
        Livewire::test(ListCampaigns::class)
            ->assertTableColumnFormattedStateSet('next_slot', '12:40', $campaigns->first())
            ->assertTableColumnFormattedStateSet('next_slot', '08:00', $campaigns->last());
        $queries = count(DB::getQueryLog());

        $campaigns->push($this->campaign(products: 1));
        DB::flushQueryLog();
        Livewire::test(ListCampaigns::class);

        $this->assertLessThanOrEqual($queries, count(DB::getQueryLog()));
    }

    private function campaign(int $products = 1, int $accounts = 1, int $postsPerDay = 1): Campaign
    {
        $affiliate = AffiliateAccount::factory()->create();
        $campaign = Campaign::factory()->for($affiliate)->create(['posts_per_day' => $postsPerDay]);
        $campaign->socialAccounts()->attach(SocialAccount::factory()->count($accounts)->create());
        Product::factory()->count($products)->for($affiliate)->create();

        return $campaign;
    }
}
