<?php

namespace App\Jobs;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one campaign draft for one account and sends it for approval. One job per account,
 * so a failure for one account does not hold up the others.
 */
class GenerateDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public SocialAccount $account,
        public Product $product,
        public Campaign $campaign,
        public ?int $campaignRunId = null,
    ) {}

    public function handle(DraftWriter $writer): void
    {
        try {
            $post = $writer->create($this->product, $this->account, $this->campaign);
        } catch (ContentGenerationException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(120 * $this->attempts());

                return;
            }

            $this->tellTheApprover($e->getMessage());

            return;
        }

        SyncApprovalMessage::dispatch($post);
    }

    public function failed(Throwable $e): void
    {
        $this->tellTheApprover("Unexpected error: {$e->getMessage()}");
    }

    private function tellTheApprover(string $reason): void
    {
        // Frees the product for a later run instead of using it up on a failure.
        if ($this->campaignRunId !== null) {
            CampaignRun::query()->whereKey($this->campaignRunId)->update(['status' => CampaignRun::FAILED]);
        }

        Log::warning('Campaign draft not written', [
            'campaign_id' => $this->campaign->id,
            'social_account_id' => $this->account->id,
            'product_id' => $this->product->id,
            'reason' => $reason,
        ]);

        $bot = app(TelegramBot::class);

        if (! $bot->configured()) {
            return;
        }

        try {
            $bot->sendMessage($bot->approvalChatId(), implode("\n", [
                '⚠️ <b>Could not write a draft</b> for <b>'.e($this->account->name).'</b>',
                '🛍 '.e($this->product->name).' ('.e($this->campaign->name).')',
                '',
                e($reason),
            ]));
        } catch (TelegramException $e) {
            Log::warning('Could not report the failed draft to Telegram', ['error' => $e->getMessage()]);
        }
    }
}
