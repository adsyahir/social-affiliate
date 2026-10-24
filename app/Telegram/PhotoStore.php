<?php

namespace App\Telegram;

use App\Models\Post;
use App\Models\Product;
use App\Telegram\Exceptions\PhotoException;
use App\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves a photo sent to the bot on the public disk, so platforms can fetch it by URL.
 * The file is untrusted: only real JPEG or PNG images within Threads' size limit are kept.
 */
class PhotoStore
{
    /**
     * Telegram fetches photos by URL only up to 5 MB (Threads allows 8 MB), so 5 MB works everywhere.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const DIRECTORY = 'post-images';

    public function __construct(private readonly TelegramBot $bot) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public static function hasPhoto(array $message): bool
    {
        return self::file($message) !== null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return string the public URL of the saved image
     *
     * @throws PhotoException
     */
    public function save(array $message): string
    {
        return $this->saveFile(self::file($message) ?? throw new PhotoException('Send the picture as a photo (or a JPEG or PNG file).'));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{file_id: string, file_size?: int}|null
     */
    public static function fileOf(array $message): ?array
    {
        return self::file($message);
    }

    /**
     * @param  array{file_id: string, file_size?: int}  $file
     * @return string the public URL of the saved image
     *
     * @throws PhotoException
     */
    public function saveFile(array $file): string
    {
        $tooLarge = 'That photo is over 5 MB. Send it as a normal photo (Telegram makes it smaller) or a smaller file.';

        if (($file['file_size'] ?? 0) > self::MAX_BYTES) {
            throw new PhotoException($tooLarge);
        }

        try {
            $info = $this->bot->getFile((string) $file['file_id']);

            if (($info['file_size'] ?? 0) > self::MAX_BYTES) {
                throw new PhotoException($tooLarge);
            }

            $bytes = $this->bot->downloadFile((string) ($info['file_path'] ?? ''), self::MAX_BYTES);
        } catch (TelegramException $e) {
            throw new PhotoException("Could not get the photo from Telegram ({$e->getMessage()}). Try sending it again.");
        }

        // Judge the content, not the name or type the sender gave it.
        $extension = match (@getimagesizefromstring($bytes)[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            default => throw new PhotoException('Only JPEG or PNG images can be posted. Send it as a normal photo.'),
        };

        $name = Str::uuid().'.'.$extension;
        Storage::disk('public')->put(self::DIRECTORY.'/'.$name, $bytes);

        // Platforms fetch the image themselves, so the link must be absolute.
        return self::baseUrl().$name;
    }

    /**
     * Whether the URL is a photo saved here (as opposed to, say, a Shopee catalogue image).
     */
    public static function isOurs(?string $url): bool
    {
        return $url !== null && str_starts_with($url, self::baseUrl());
    }

    /**
     * Removes a photo saved here, unless a post or product still uses it.
     */
    public static function deleteIfUnused(?string $url): void
    {
        if (! self::isOurs($url)
            || Post::query()->where('image_url', $url)->exists()
            || Post::query()->whereJsonContains('images', $url)->exists()
            || Product::query()->where('image_url', $url)->exists()) {
            return;
        }

        Storage::disk('public')->delete(self::DIRECTORY.'/'.basename((string) parse_url((string) $url, PHP_URL_PATH)));
    }

    private static function baseUrl(): string
    {
        $url = Storage::disk('public')->url(self::DIRECTORY.'/');

        return str_starts_with($url, '/') ? rtrim((string) config('app.url'), '/').$url : $url;
    }

    /**
     * The largest size of a photo, or an image sent as a file.
     *
     * @param  array<string, mixed>  $message
     * @return array{file_id: string, file_size?: int}|null
     */
    private static function file(array $message): ?array
    {
        if (! empty($message['photo']) && is_array($message['photo'])) {
            return collect($message['photo'])->sortBy(fn (array $size): int => (int) ($size['file_size'] ?? $size['width'] ?? 0))->last();
        }

        $document = $message['document'] ?? null;

        return is_array($document) && str_starts_with((string) ($document['mime_type'] ?? ''), 'image/') ? $document : null;
    }
}
