<?php

namespace App\Social\Facebook;

use App\Models\SocialAccount;
use App\Social\Concerns\PublishesOnce;
use App\Social\Contracts\SocialPublisher;
use App\Social\Data\AccountProfile;
use App\Social\Data\PostContent;
use App\Social\Data\PostMetrics;
use App\Social\Data\PublishedPost;
use App\Social\Exceptions\AccessTokenException;
use App\Social\Exceptions\InvalidContentException;
use App\Social\Exceptions\MissingTokenException;
use App\Social\Exceptions\SocialApiException;
use App\Social\Meta\GraphClient;
use Illuminate\Support\Facades\Log;

/**
 * A Facebook Page, posting with a Page access token. Tokens made from a long-lived user token
 * do not expire, so there is nothing to refresh. The link reply is a comment by the Page.
 */
class FacebookPagePublisher implements SocialPublisher
{
    use PublishesOnce;

    /**
     * Facebook allows far longer posts; this keeps drafts to a length people read on a feed.
     */
    private const MAX_LENGTH = 5000;

    private ?string $pageId;

    public function __construct(private readonly SocialAccount $account)
    {
        $this->pageId = $account->external_id;
    }

    public static function hasInsights(): bool
    {
        return true;
    }

    public function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function verify(): AccountProfile
    {
        $page = $this->client()->get($this->path('me'), ['fields' => 'id,name']);

        return new AccountProfile((string) $page['id'], (string) ($page['name'] ?? ''), isHandle: false);
    }

    public function publish(PostContent $content): PublishedPost
    {
        $this->validate($content);
        $client = $this->client();
        $pageId = $this->pageId();

        $response = $this->publishOnce('Facebook', fn (): array => $content->imageUrl === null
            ? $client->post($this->path("{$pageId}/feed"), ['message' => $content->text])
            : $client->post($this->path("{$pageId}/photos"), ['url' => $content->imageUrl, 'caption' => $content->text]));

        // A photo upload answers with the photo's ID and, separately, the post it created.
        $id = (string) ($response['post_id'] ?? $response['id']);

        return new PublishedPost($id, $this->permalink($client, $id));
    }

    public function reply(string $parentId, PostContent $content): PublishedPost
    {
        $this->validate($content);
        $client = $this->client();

        $comment = $this->publishOnce('Facebook', fn (): array => $client->post($this->path("{$parentId}/comments"), ['message' => $content->text]));

        return new PublishedPost((string) $comment['id']);
    }

    public function getInsights(string $externalPostId): PostMetrics
    {
        $client = $this->client();

        $post = $client->get($this->path($externalPostId), [
            'fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares',
        ]);

        return new PostMetrics(
            views: $this->views($client, $externalPostId),
            likes: (int) ($post['reactions']['summary']['total_count'] ?? 0),
            replies: (int) ($post['comments']['summary']['total_count'] ?? 0),
            reposts: (int) ($post['shares']['count'] ?? 0),
        );
    }

    /**
     * Views come from the post_media_view insight, which replaced the impression metrics Meta
     * retired in 2026. It needs the read_insights permission and Meta keeps changing these
     * metrics, so a rejection costs only the view count, not the whole snapshot.
     */
    private function views(GraphClient $client, string $postId): int
    {
        try {
            $insights = $client->get($this->path("{$postId}/insights"), ['metric' => 'post_media_view']);
        } catch (AccessTokenException $e) {
            throw $e;
        } catch (SocialApiException $e) {
            Log::info('Facebook views not available', ['post_id' => $postId, 'error' => $e->getMessage()]);

            return 0;
        }

        return (int) ($insights['data'][0]['values'][0]['value'] ?? 0);
    }

    private function permalink(GraphClient $client, string $postId): ?string
    {
        try {
            return $client->get($this->path($postId), ['fields' => 'permalink_url'])['permalink_url'] ?? null;
        } catch (SocialApiException) {
            return null;
        }
    }

    private function validate(PostContent $content): void
    {
        if (trim($content->text) === '') {
            throw new InvalidContentException('A Facebook post needs some text.');
        }

        if (mb_strlen($content->text) > self::MAX_LENGTH) {
            throw new InvalidContentException('Facebook posts here are kept under '.self::MAX_LENGTH.' characters; this one has '.mb_strlen($content->text).'.');
        }
    }

    private function pageId(): string
    {
        return $this->pageId ??= $this->verify()->id;
    }

    private function path(string $path): string
    {
        return config('services.facebook.version').'/'.$path;
    }

    private function client(): GraphClient
    {
        if (blank($this->account->access_token)) {
            throw new MissingTokenException("\"{$this->account->name}\" has no Facebook Page access token.");
        }

        return new GraphClient((string) config('services.facebook.base_url'), $this->account->access_token, 'Facebook');
    }
}
