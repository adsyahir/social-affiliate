<?php

namespace App\Telegram;

use App\Jobs\SettleAlbum;
use App\Models\Post;
use App\Telegram\Exceptions\PhotoException;
use Illuminate\Support\Facades\Cache;

/**
 * Gathers the photos of a Telegram album. An album arrives as one message per photo, possibly at
 * the same time and in any order, and only one of them may be the reply that names the post. Each
 * photo is saved as it arrives; a few seconds after the first, SettleAlbum puts them on the post
 * in one go, so the chat gets one answer for the whole album.
 *
 * Only a reply decides the post. Photos never pick one themselves, so an album cannot land on a
 * post just because it happened to be the one waiting.
 */
class AlbumCollector
{
    /**
     * How long after the first photo the album is put together.
     */
    public const SETTLE_SECONDS = 3;

    private const MINUTES = 15;

    public function __construct(private readonly PhotoStore $photos) {}

    /**
     * @param  array<string, mixed>  $message
     * @param  Post|null  $replyTarget  the post this message replies to, if it does
     * @param  bool  $isLink  the caption starts a new post from a link, so the album goes nowhere
     */
    public function record(string $albumId, array $message, string $chatId, ?Post $replyTarget, string $text, bool $isLink): void
    {
        $messageId = (int) $message['message_id'];
        $file = PhotoStore::fileOf($message);
        $url = null;
        $error = null;

        // Downloaded before taking the lock, so the lock is only held for a moment.
        if ($file !== null) {
            try {
                $url = $this->photos->saveFile($file);
            } catch (PhotoException $e) {
                $error = $e->getMessage();
            }
        }

        $schedule = $this->update($albumId, function (array $state) use ($chatId, $messageId, $url, $error, $replyTarget, $text, $isLink): array {
            $state['chat_id'] = $chatId;
            $state['photos'][$messageId] = ['url' => $url, 'error' => $error];
            $state['last_message'] = max((int) ($state['last_message'] ?? 0), $messageId);

            if ($replyTarget !== null) {
                $state['post_id'] = $replyTarget->id;
                $state['reply_to'] = $messageId;
            }

            if ($isLink) {
                $state['link'] = true;
            } elseif ($text !== '') {
                $state['caption'] = $text;
                $state['caption_message'] = $messageId;
            }

            $first = ! ($state['scheduled'] ?? false);
            $state['scheduled'] = true;

            return [$state, $first];
        });

        if ($schedule) {
            SettleAlbum::dispatch($albumId)->delay(now()->addSeconds(self::SETTLE_SECONDS));
        }
    }

    /**
     * The album as gathered so far, for settling. A photo arriving after this schedules another settle.
     *
     * @return array<string, mixed>|null
     */
    public function take(string $albumId): ?array
    {
        return $this->update($albumId, function (array $state): array {
            $state['scheduled'] = false;

            return [$state, $state];
        });
    }

    /**
     * Records what settling did (what was said, applied or reported), so a later settle does not repeat it.
     *
     * @param  array<string, mixed>  $flags
     */
    public function remember(string $albumId, array $flags): void
    {
        $this->update($albumId, fn (array $state): array => [[...$state, ...$flags], null]);
    }

    /**
     * @param  callable(array<string, mixed>): array{array<string, mixed>, mixed}  $change
     */
    private function update(string $albumId, callable $change): mixed
    {
        $key = "telegram-album:{$albumId}";

        return Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $change): mixed {
            [$state, $result] = $change(Cache::get($key) ?? ['photos' => []]);
            Cache::put($key, $state, now()->addMinutes(self::MINUTES));

            return $result;
        });
    }
}
