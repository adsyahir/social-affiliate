<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Jobs\FetchPostInsights;
use App\Models\Post;
use App\Social\SocialPublisherFactory;
use Illuminate\Console\Command;

class FetchInsights extends Command
{
    protected $signature = 'insights:fetch
        {--days= : Only posts published within this many days (default: INSIGHTS_DAYS, 30)}';

    protected $description = 'Queue a metrics snapshot for every recently published post (run daily by the scheduler)';

    /**
     * Jobs are spaced out so a big batch does not hit the platforms' rate limits all at once.
     */
    private const SECONDS_BETWEEN_JOBS = 2;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('services.insights.days'));
        $platforms = array_values(array_filter(
            array_map(fn (SocialPlatform $platform) => $platform->value, SocialPlatform::cases()),
            fn (string $platform) => SocialPublisherFactory::supportsInsights(SocialPlatform::from($platform)),
        ));

        $queued = 0;

        Post::query()
            ->where('status', PostStatus::Published)
            ->whereNotNull('external_post_id')
            ->where('published_at', '>=', now()->subDays($days))
            ->whereHas('socialAccount', fn ($query) => $query->whereIn('platform', $platforms))
            ->orderBy('id')
            ->each(function (Post $post) use (&$queued): void {
                FetchPostInsights::dispatch($post)->delay(now()->addSeconds($queued * self::SECONDS_BETWEEN_JOBS));
                $queued++;
            });

        $this->info("Queued insights for {$queued} ".str('post')->plural($queued)." published in the last {$days} days.");

        return self::SUCCESS;
    }
}
