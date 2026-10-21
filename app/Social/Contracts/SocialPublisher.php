<?php

namespace App\Social\Contracts;

use App\Social\Data\AccountProfile;
use App\Social\Data\PostContent;
use App\Social\Data\PostMetrics;
use App\Social\Data\PublishedPost;
use App\Social\Exceptions\InvalidContentException;
use App\Social\Exceptions\MissingTokenException;
use App\Social\Exceptions\SocialApiException;

/**
 * One driver per social platform, bound to a single social account.
 *
 * Every method can throw MissingTokenException (no token stored) or SocialApiException;
 * check its `retryable` flag to decide between retrying later and asking for a fix.
 */
interface SocialPublisher
{
    /**
     * Whether the platform reports post metrics. When false, the insights job skips its posts.
     */
    public static function hasInsights(): bool;

    /**
     * The longest post the platform accepts, in the platform's own units.
     */
    public function maxLength(): int;

    /**
     * Confirm the token works and return the account it belongs to.
     *
     * @throws MissingTokenException
     * @throws SocialApiException
     */
    public function verify(): AccountProfile;

    /**
     * @throws InvalidContentException When the content breaks the platform's limits.
     * @throws MissingTokenException
     * @throws SocialApiException
     */
    public function publish(PostContent $content): PublishedPost;

    /**
     * Publish a reply under one of this account's posts.
     *
     * @throws InvalidContentException
     * @throws MissingTokenException
     * @throws SocialApiException
     */
    public function reply(string $parentId, PostContent $content): PublishedPost;

    /**
     * @throws MissingTokenException
     * @throws SocialApiException
     */
    public function getInsights(string $externalPostId): PostMetrics;
}
