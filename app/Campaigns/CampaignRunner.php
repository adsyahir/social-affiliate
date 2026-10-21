<?php

namespace App\Campaigns;

use App\Jobs\GenerateDraft;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Product;
use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starts a campaign's batches: the next product it has never used, drafted for every social
 * account in the campaign. Each batch is recorded before its jobs run, so a slot cannot fire twice.
 */
class CampaignRunner
{
    public function __construct(
        private readonly PostingSchedule $schedule,
        private readonly TelegramBot $bot,
    ) {}

    /**
     * Run one batch for each active campaign that has a slot due and unused.
     *
     * @return list<CampaignRun>
     */
    public function runDue(): array
    {
        $now = now();

        if (! $this->schedule->inWindow($now)) {
            return [];
        }

        $runs = [];

        Campaign::query()->active()->whereHas('socialAccounts')->each(function (Campaign $campaign) use ($now, &$runs): void {
            // Catch-up after downtime happens one batch per run, never in a burst.
            if ($this->usedToday($campaign) < $this->schedule->dueCount($campaign->posts_per_day, $now)) {
                $runs[] = $this->run($campaign, CampaignRun::SCHEDULE);
            }
        });

        return array_values(array_filter($runs));
    }

    /**
     * Start one batch now. Returns null when the campaign has no social accounts.
     */
    public function run(Campaign $campaign, string $trigger): ?CampaignRun
    {
        $accounts = $campaign->socialAccounts()->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        // A scheduled tick and "Run now" at the same moment must not both pick the same product.
        return Cache::lock("campaign-run:{$campaign->id}", 30)->block(10, function () use ($campaign, $accounts, $trigger): CampaignRun {
            $product = $this->nextProduct($campaign);

            if ($product === null) {
                return $this->reportNoProducts($campaign, $trigger);
            }

            $run = DB::transaction(fn (): CampaignRun => $campaign->runs()->create([
                'product_id' => $product->id,
                'status' => CampaignRun::DISPATCHED,
                'trigger' => $trigger,
                'accounts' => $accounts->count(),
            ]));

            foreach ($accounts as $account) {
                GenerateDraft::dispatch($account, $product, $campaign, $run->id);
            }

            return $run;
        });
    }

    /**
     * Scheduled batches already started today. Failed ones count too, so a lasting problem (a
     * missing API key, say) is reported at most once per slot. Manual runs and "no products"
     * notices don't count.
     */
    public function usedToday(Campaign $campaign): int
    {
        return $campaign->runs()
            ->where('trigger', CampaignRun::SCHEDULE)
            ->whereIn('status', [CampaignRun::DISPATCHED, CampaignRun::FAILED])
            ->where('created_at', '>=', $this->schedule->startOfDay(now()))
            ->count();
    }

    /**
     * The oldest available product that no run is working on and nobody has drafted yet, so a
     * rejected draft is not followed by the same product again. Products whose run failed are
     * eligible again.
     */
    private function nextProduct(Campaign $campaign): ?Product
    {
        return $campaign->availableProducts()
            ->whereDoesntHave('posts')
            ->whereNotIn('id', CampaignRun::query()->where('status', CampaignRun::DISPATCHED)->whereNotNull('product_id')->select('product_id'))
            ->first();
    }

    private function reportNoProducts(Campaign $campaign, string $trigger): CampaignRun
    {
        $today = $campaign->runs()
            ->where('status', CampaignRun::NO_PRODUCTS)
            ->where('created_at', '>=', $this->schedule->startOfDay(now()))
            ->first();

        if ($today !== null) {
            return $today;
        }

        $run = $campaign->runs()->create(['status' => CampaignRun::NO_PRODUCTS, 'trigger' => $trigger]);

        if ($this->bot->configured()) {
            try {
                $this->bot->sendMessage(
                    $this->bot->approvalChatId(),
                    '📦 Campaign <b>'.e($campaign->name).'</b> has no products left to post. Import or add products in the admin.',
                );
            } catch (TelegramException $e) {
                Log::warning('Could not send the no-products notice', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
            }
        }

        return $run;
    }
}
