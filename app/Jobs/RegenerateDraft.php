<?php

namespace App\Jobs;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rewrites a draft (optionally with feedback from the approval chat) and shows the new version.
 */
class RegenerateDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Post $post,
        public ?string $feedback = null,
    ) {}

    public function handle(DraftWriter $writer): void
    {
        try {
            $writer->regenerate($this->post, $this->feedback);
        } catch (ContentGenerationException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(60);

                return;
            }

            // The old version stays, with its buttons back, and the reason underneath.
            SyncApprovalMessage::dispatchSync($this->post->fresh(), "⚠️ Could not rewrite it: {$e->getMessage()}");

            return;
        }

        SyncApprovalMessage::dispatchSync($this->post->fresh(), '🔄 New version.');
    }

    /**
     * Any other crash: the message lost its buttons when the rewrite started, so give them back.
     */
    public function failed(Throwable $e): void
    {
        if ($post = $this->post->fresh()) {
            SyncApprovalMessage::dispatchSync($post, "⚠️ Could not rewrite it: {$e->getMessage()}");
        }
    }
}
