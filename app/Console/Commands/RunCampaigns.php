<?php

namespace App\Console\Commands;

use App\Campaigns\CampaignRunner;
use App\Models\CampaignRun;
use Illuminate\Console\Command;

class RunCampaigns extends Command
{
    protected $signature = 'campaigns:run';

    protected $description = 'Start draft batches for campaigns with a posting slot due (run by the scheduler every 15 minutes)';

    public function handle(CampaignRunner $runner): int
    {
        foreach ($runner->runDue() as $run) {
            $this->line(match ($run->status) {
                CampaignRun::DISPATCHED => "{$run->campaign->name}: writing drafts for \"{$run->product->name}\" ({$run->accounts} accounts).",
                default => "{$run->campaign->name}: no products left.",
            });
        }

        return self::SUCCESS;
    }
}
