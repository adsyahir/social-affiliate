<?php

namespace App\Social\Telegram;

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
use App\Social\Exceptions\SocialException;
use App\Telegram\DraftMessage;
use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;

/**
 * A Telegram channel, posting through a bot that is an admin of the channel. The account's
 * access token is that bot's token and its platform ID is the channel (@name or -100...).
 */
class TelegramChannelPublisher implements SocialPublisher
{
    use PublishesOnce;

    private const MAX_LENGTH = 4096;

    /**
     * Telegram's limit for a photo caption; longer text follows the photo as its own message.
     */
    private const MAX_CAPTION = 1024;

    public function __construct(private readonly SocialAccount $account) {}

    /**
     * The Bot API does not expose view counts for channel posts.
     */
    public static function hasInsights(): bool
    {
        return false;
    }

    public function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function verify(): AccountProfile
    {
        $chat = $this->call(fn (TelegramBot $bot): array => $bot->getChat($this->channel()));

        return isset($chat['username'])
            ? new AccountProfile((string) $chat['id'], (string) $chat['username'])
            : new AccountProfile((string) $chat['id'], (string) ($chat['title'] ?? ''), isHandle: false);
    }

    public function publish(PostContent $content): PublishedPost
    {
        $this->validate($content);
        $channel = $this->channel();

        $id = $this->publishOnce('Telegram', fn (): int => $this->call(function (TelegramBot $bot) use ($channel, $content): int {
            $images = $content->images();

            // No preview on the main post; it has no links anyway.
            if ($images === []) {
                return $bot->sendText($channel, $content->text);
            }

            // Telegram counts the caption in UTF-16 units, as it does message text.
            $fits = DraftMessage::telegramLength(htmlspecialchars($content->text, ENT_NOQUOTES)) <= self::MAX_CAPTION;
            $send = fn (?string $caption): int => count($images) === 1
                ? $bot->sendPhoto($channel, $images[0], $caption)
                : $bot->sendMediaGroup($channel, $images, $caption);

            if ($fits) {
                return $send($content->text);
            }

            // Too long for a caption: the photos, then the text, which the link reply answers.
            $send(null);

            try {
                return $bot->sendText($channel, $content->text);
            } catch (TelegramException $e) {
                // The photos are already in the channel; a retry would post them again.
                throw new TelegramException("The photo was posted but the text failed: {$e->getMessage()}. Check the channel before retrying.", retryable: false, status: $e->status);
            }
        }));

        return new PublishedPost((string) $id, $this->permalink($channel, $id));
    }

    public function reply(string $parentId, PostContent $content): PublishedPost
    {
        $this->validate($content);
        $channel = $this->channel();

        // The reply carries the product link, so its preview card is shown.
        $id = $this->publishOnce('Telegram', fn (): int => $this->call(fn (TelegramBot $bot): int => $bot->sendText($channel, $content->text, (int) $parentId, linkPreview: true)));

        return new PublishedPost((string) $id, $this->permalink($channel, $id));
    }

    public function getInsights(string $externalPostId): PostMetrics
    {
        throw new SocialException('Telegram channels have no view counts in the Bot API.');
    }

    /**
     * @template T
     *
     * @param  callable(TelegramBot): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        if (blank($this->account->access_token)) {
            throw new MissingTokenException("\"{$this->account->name}\" has no bot token.");
        }

        try {
            return $call(new TelegramBot($this->account->access_token));
        } catch (TelegramException $e) {
            if ($e->status === 401) {
                throw new AccessTokenException("Telegram rejected the bot token: {$e->getMessage()}", apiCode: 401);
            }

            throw new SocialApiException($e->getMessage(), retryable: $e->retryable, apiCode: $e->status);
        }
    }

    private function permalink(string $channel, int $messageId): ?string
    {
        if (str_starts_with($channel, '@')) {
            return 'https://t.me/'.substr($channel, 1)."/{$messageId}";
        }

        // Private channels: -100 followed by the internal ID.
        if (str_starts_with($channel, '-100')) {
            return 'https://t.me/c/'.substr($channel, 4)."/{$messageId}";
        }

        return null;
    }

    private function validate(PostContent $content): void
    {
        if (trim($content->text) === '') {
            throw new InvalidContentException('A Telegram post needs some text.');
        }

        if (mb_strlen($content->text) > self::MAX_LENGTH) {
            throw new InvalidContentException('Telegram allows '.self::MAX_LENGTH.' characters; this post has '.mb_strlen($content->text).'.');
        }

    }

    private function channel(): string
    {
        return filled($this->account->external_id)
            ? (string) $this->account->external_id
            : throw new SocialException("Set the channel (@name or -100... ID) on \"{$this->account->name}\".");
    }
}
