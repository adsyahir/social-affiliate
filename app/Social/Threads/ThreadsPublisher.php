<?php

namespace App\Social\Threads;

use App\Models\SocialAccount;
use App\Social\Concerns\PublishesOnce;
use App\Social\Contracts\RefreshesAccessToken;
use App\Social\Contracts\SocialPublisher;
use App\Social\Data\AccessToken;
use App\Social\Data\AccountProfile;
use App\Social\Data\PostContent;
use App\Social\Data\PostMetrics;
use App\Social\Data\PublishedPost;
use App\Social\Exceptions\InvalidContentException;
use App\Social\Exceptions\MissingTokenException;
use App\Social\Exceptions\SocialApiException;
use App\Social\Meta\GraphClient;
use Illuminate\Support\Sleep;

/**
 * Threads: every post is a media container that is created, checked until ready, then published.
 */
class ThreadsPublisher implements RefreshesAccessToken, SocialPublisher
{
    use PublishesOnce;

    private const VERSION = 'v1.0';

    private const MAX_LENGTH = 500;

    private const MAX_LINKS = 5;

    private const METRICS = ['views', 'likes', 'replies', 'reposts'];

    private ?string $userId;

    public function __construct(private readonly SocialAccount $account)
    {
        $this->userId = $account->external_id;
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
        $me = $this->client()->get(self::VERSION.'/me', ['fields' => 'id,username']);

        return new AccountProfile((string) $me['id'], (string) ($me['username'] ?? ''));
    }

    public function publish(PostContent $content): PublishedPost
    {
        return $this->createAndPublish($content);
    }

    public function reply(string $parentId, PostContent $content): PublishedPost
    {
        return $this->createAndPublish($content, ['reply_to_id' => $parentId]);
    }

    public function getInsights(string $externalPostId): PostMetrics
    {
        $response = $this->client()->get(self::VERSION."/{$externalPostId}/insights", ['metric' => implode(',', self::METRICS)]);

        $values = [];
        foreach ($response['data'] ?? [] as $metric) {
            $values[$metric['name']] = (int) ($metric['values'][0]['value'] ?? $metric['total_value']['value'] ?? 0);
        }

        return new PostMetrics(
            views: $values['views'] ?? 0,
            likes: $values['likes'] ?? 0,
            replies: $values['replies'] ?? 0,
            reposts: $values['reposts'] ?? 0,
        );
    }

    public function refreshAccessToken(): AccessToken
    {
        $response = $this->client()->get('refresh_access_token', ['grant_type' => 'th_refresh_token']);

        return new AccessToken(
            (string) $response['access_token'],
            isset($response['expires_in']) ? now()->toImmutable()->addSeconds((int) $response['expires_in']) : null,
        );
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function createAndPublish(PostContent $content, array $extra = []): PublishedPost
    {
        $this->validate($content);
        $client = $this->client();
        $userId = $this->userId();

        $container = $client->post(self::VERSION."/{$userId}/threads", [
            ...$content->imageUrl === null
                ? ['media_type' => 'TEXT']
                : ['media_type' => 'IMAGE', 'image_url' => $content->imageUrl],
            'text' => $content->text,
            ...$extra,
        ]);

        $this->waitUntilReady($client, (string) $container['id']);

        $published = $this->publishOnce('Threads', fn (): array => $client->post(self::VERSION."/{$userId}/threads_publish", ['creation_id' => (string) $container['id']]));

        $id = (string) $published['id'];

        return new PublishedPost($id, $this->permalink($client, $id));
    }

    /**
     * Threads processes containers asynchronously; publishing before they are FINISHED fails.
     */
    private function waitUntilReady(GraphClient $client, string $containerId): void
    {
        $checks = max(1, (int) config('services.threads.status_checks'));

        for ($check = 1; $check <= $checks; $check++) {
            $container = $client->get(self::VERSION."/{$containerId}", ['fields' => 'status,error_message']);
            $status = $container['status'] ?? null;

            if ($status === 'FINISHED') {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED', 'PUBLISHED'], true)) {
                $reason = $container['error_message'] ?? 'no reason given';

                throw new SocialApiException("Threads could not prepare the post ({$status}: {$reason}).");
            }

            if ($check < $checks) {
                Sleep::for((int) config('services.threads.status_check_seconds'))->seconds();
            }
        }

        throw new SocialApiException('Threads is still processing the post; try again shortly.', retryable: true);
    }

    /**
     * The link to the post on threads.com, for approval messages. A failure here is not worth
     * failing a post that is already live, so it returns null instead.
     */
    private function permalink(GraphClient $client, string $mediaId): ?string
    {
        try {
            return $client->get(self::VERSION."/{$mediaId}", ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (SocialApiException) {
            return null;
        }
    }

    private function validate(PostContent $content): void
    {
        if (trim($content->text) === '') {
            throw new InvalidContentException('A Threads post needs some text.');
        }

        $length = $this->length($content->text);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidContentException('Threads allows '.self::MAX_LENGTH." characters; this post has {$length}.");
        }

        preg_match_all('~https?://[^\s]+~i', $content->text, $matches);
        $links = count(array_unique($matches[0]));
        if ($links > self::MAX_LINKS) {
            throw new InvalidContentException('Threads allows '.self::MAX_LINKS." links in a post; this one has {$links}.");
        }
    }

    /**
     * Threads counts emoji by their UTF-8 bytes. Counting every character outside the 1-2 byte
     * range that way keeps the check on the safe side for any script.
     */
    private function length(string $text): int
    {
        $length = 0;
        foreach (mb_str_split($text) as $character) {
            $bytes = strlen($character);
            $length += $bytes <= 2 ? 1 : $bytes;
        }

        return $length;
    }

    private function userId(): string
    {
        return $this->userId ??= $this->verify()->id;
    }

    private function client(): GraphClient
    {
        if (blank($this->account->access_token)) {
            throw new MissingTokenException("\"{$this->account->name}\" has no Threads access token.");
        }

        return new GraphClient((string) config('services.threads.base_url'), $this->account->access_token, 'Threads');
    }
}
