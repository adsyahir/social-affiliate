<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Enums\ProductStatus;
use App\Models\Post;
use App\Social\Data\PostContent;
use App\Social\Exceptions\SocialApiException;
use App\Social\Exceptions\SocialException;
use App\Social\SocialPublisherFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes an approved post, then the reply that carries the affiliate link.
 *
 * Each external ID is saved as soon as it exists, so a retry picks up where the last attempt
 * stopped instead of posting the main post twice.
 */
class PublishPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    // A carousel waits for Threads to prepare each photo.
    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Post $post) {}

    public function handle(SocialPublisherFactory $publishers): void
    {
        $post = $this->post->fresh();

        if ($post === null || $post->status !== PostStatus::Approved) {
            return;
        }

        try {
            $publisher = $publishers->make($post->socialAccount);

            if ($post->external_post_id === null) {
                $published = $publisher->publish(new PostContent($post->content, $post->imageUrl(), $post->imageUrls()));
                $post->update(['external_post_id' => $published->id, 'permalink' => $published->permalink]);
            }

            if ($post->link_reply_id === null && filled($post->link_reply_content)) {
                $reply = $publisher->reply($post->external_post_id, new PostContent($post->link_reply_content));
                $post->update(['link_reply_id' => $reply->id]);
            }
        } catch (SocialException $e) {
            $this->handleFailure($post, $e);

            return;
        }

        $post->update(['status' => PostStatus::Published, 'published_at' => now(), 'last_error' => null]);

        if ($post->product?->status === ProductStatus::Available) {
            $post->product->update(['status' => ProductStatus::Used]);
        }

        SyncApprovalMessage::dispatchSync($post);
    }

    private function handleFailure(Post $post, SocialException $e): void
    {
        $message = $post->external_post_id !== null
            ? "The post is live, but the link reply failed: {$e->getMessage()}"
            : $e->getMessage();

        if ($e instanceof SocialApiException && $e->retryable && $this->attempts() < $this->tries) {
            $post->update(['last_error' => $message]);
            $this->release(min(60 * 2 ** ($this->attempts() - 1), 900));

            return;
        }

        Log::error('Publishing failed', ['post_id' => $post->id, 'error' => $message]);
        $post->update(['status' => PostStatus::Failed, 'last_error' => $message]);
        SyncApprovalMessage::dispatchSync($post);
    }

    /**
     * Any other crash (database, bug) after the last try: never leave the post stuck on "Publishing".
     */
    public function failed(Throwable $e): void
    {
        $post = $this->post->fresh();

        if ($post === null || $post->status !== PostStatus::Approved) {
            return;
        }

        $post->update(['status' => PostStatus::Failed, 'last_error' => "Unexpected error: {$e->getMessage()}"]);
        SyncApprovalMessage::dispatchSync($post);
    }
}
