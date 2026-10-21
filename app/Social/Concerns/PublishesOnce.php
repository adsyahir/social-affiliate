<?php

namespace App\Social\Concerns;

use App\Social\Exceptions\SocialApiException;

trait PublishesOnce
{
    /**
     * Send the call that makes a post go live. If the connection drops before an answer arrives,
     * the post may already be live, so the error is marked not retryable: a retry could post twice.
     *
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    protected function publishOnce(string $platform, callable $send): mixed
    {
        try {
            return $send();
        } catch (SocialApiException $e) {
            if ($e->retryable && $e->apiCode === null) {
                throw new SocialApiException(
                    "Lost contact with {$platform} while publishing; the post may have been published. Check the account before retrying. ({$e->getMessage()})",
                );
            }

            throw $e;
        }
    }
}
