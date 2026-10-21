<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostInsight;
use App\Social\Exceptions\SocialApiException;
use App\Social\Exceptions\SocialException;
use App\Social\SocialPublisherFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Saves a snapshot of one published post's metrics. Snapshots accumulate, so growth over
 * time can be read back, not only the latest totals.
 */
class FetchPostInsights implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Post $post) {}

    public function handle(SocialPublisherFactory $publishers): ?PostInsight
    {
        $account = $this->post->socialAccount;

        if ($this->post->external_post_id === null || ! SocialPublisherFactory::supportsInsights($account->platform)) {
            return null;
        }

        try {
            $metrics = $publishers->make($account)->getInsights($this->post->external_post_id);
        } catch (SocialApiException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(300 * $this->attempts());

                return null;
            }

            $this->logFailure($e);

            return null;
        } catch (SocialException $e) {
            $this->logFailure($e);

            return null;
        }

        return $this->post->insights()->create([...$metrics->toArray(), 'fetched_at' => now()]);
    }

    /**
     * Logged rather than sent to Telegram: a dead token would otherwise mean one message per post.
     */
    private function logFailure(SocialException $e): void
    {
        Log::warning('Insights not fetched', ['post_id' => $this->post->id, 'error' => $e->getMessage()]);
    }
}
