<?php

namespace App\Telegram;

use App\Content\DraftWriter;
use App\Content\PromptBuilder;
use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Models\Post;

/**
 * How a post looks in the Telegram approval chat, and which buttons it offers in each state.
 */
class DraftMessage
{
    /**
     * Telegram's limit per message, counted in UTF-16 units of the text after HTML parsing.
     */
    private const MAX_LENGTH = 4096;

    public static function text(Post $post, ?string $note = null): string
    {
        if ($post->status === PostStatus::Pending) {
            return self::pendingText($post, $note === null ? '' : "\n\n".self::e($note));
        }

        // Skipped while waiting for your text, so there is no draft to show.
        if ($post->status === PostStatus::Rejected && blank($post->content)) {
            return implode("\n", [
                "⏭ <b>Skipped: post #{$post->id}</b> for <b>".self::e($post->socialAccount->name).'</b>',
                '🛍 '.self::e($post->product?->name ?? 'No product'),
                '',
                '<i>'.self::e("Skipped by {$post->approved_by}. Reply with your text if you change your mind.").'</i>',
            ]);
        }

        $lines = [
            "📝 <b>Draft #{$post->id}</b> for <b>".self::e($post->socialAccount->name).'</b>',
            '🛍 '.self::e($post->product?->name ?? 'No product'),
            '',
            '<b>Post</b>',
            self::e($post->content),
            '',
            '<b>Link reply</b>',
            self::e((string) $post->link_reply_content),
            ...($post->status === PostStatus::Draft && self::disclosure($post) !== null
                ? ['', '🏷 Disclosure: '.(self::hasDisclosure($post) ? 'included' : 'not included')]
                : []),
            ...self::photoLines($post),
            '',
            '<i>'.self::e(self::status($post)).'</i>',
        ];

        if (self::writtenByYou($post)) {
            $lines[] = '<i>Written by you</i>';
        } elseif (filled($post->generation['model'] ?? null)) {
            $lines[] = '<i>Written by '.self::e($post->generation['model']).'</i>';
        }

        if ($note !== null) {
            $lines[] = '';
            $lines[] = self::e($note);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>|null
     */
    public static function keyboard(Post $post): ?array
    {
        $button = fn (string $text, string $action): array => ['text' => $text, 'callback_data' => "{$action}:{$post->id}"];

        // Text you wrote is changed by replying with new text, so there is no AI Regenerate button.
        $yours = self::writtenByYou($post);

        // Offered when the account has a disclosure line: add it to this post, or take it out again.
        $disclosureRow = self::disclosure($post) === null ? [] : [[self::hasDisclosure($post)
            ? $button('➖ Remove disclosure', 'disc')
            : $button('➕ Add disclosure', 'disc')]];

        return match ($post->status) {
            PostStatus::Pending => [[$button('⏭ Skip', 'reject')]],
            PostStatus::Draft => [...$yours
                ? [[$button('✅ Approve', 'approve'), $button('❌ Reject', 'reject')]]
                : [[$button('✅ Approve', 'approve'), $button('❌ Reject', 'reject'), $button('🔄 Regenerate', 'regen')]], ...$disclosureRow],
            PostStatus::Rejected => $yours ? null : [[$button('🔄 Regenerate', 'regen')]],
            PostStatus::Failed => [[$button('🔁 Retry publishing', 'retry')]],
            PostStatus::Approved, PostStatus::Published => null,
        };
    }

    private static function status(Post $post): string
    {
        $yours = self::writtenByYou($post);

        return match ($post->status) {
            PostStatus::Pending => 'Waiting for your text.',
            PostStatus::Draft => $yours
                ? 'Tap Approve, or reply to this message with new text to replace it.'
                : 'Tap a button, or reply to this message with feedback to get a rewrite.',
            PostStatus::Approved => "✅ Approved by {$post->approved_by}. Publishing...",
            PostStatus::Published => '✅ Published'.($post->permalink ? ": {$post->permalink}" : '.'),
            PostStatus::Rejected => $yours
                ? "❌ Rejected by {$post->approved_by}. Reply with new text to try again."
                : "❌ Rejected by {$post->approved_by}. Reply with feedback or tap Regenerate to try again.",
            PostStatus::Failed => '⚠️ Publishing failed: '.($post->last_error ?? 'unknown error'),
        };
    }

    public static function disclosure(Post $post): ?string
    {
        return $post->socialAccount === null ? null : PromptBuilder::disclosure($post->socialAccount);
    }

    public static function hasDisclosure(Post $post): bool
    {
        $disclosure = self::disclosure($post);

        return $disclosure !== null && str_contains(mb_strtolower((string) $post->link_reply_content), mb_strtolower($disclosure));
    }

    public static function writtenByYou(Post $post): bool
    {
        return $post->writtenByYou();
    }

    private static function pendingText(Post $post, string $note = ''): string
    {
        $intro = implode("\n", [
            "✍️ <b>Your turn: post #{$post->id}</b> for <b>".self::e($post->socialAccount->name).'</b>',
            '🛍 '.self::e($post->product?->name ?? 'No product'),
            ...self::photoLines($post),
            '',
            'Reply to this message with your post. Paste Claude\'s whole answer (POST: / REPLY:) or just the post; the link (and your disclosure, if you set one) is added for you.',
            '',
        ]);

        if ($post->product !== null) {
            $prompt = app(DraftWriter::class)->copyPrompt($post->product, $post->socialAccount);
            $text = $intro."\n<b>Prompt for Claude.ai</b> (tap to copy):\n<pre>".self::e($prompt).'</pre>'.$note;

            if (self::telegramLength($text) <= self::MAX_LENGTH) {
                return $text;
            }
        }

        return $intro."\nThe prompt is too long for Telegram; open this post in the admin and use Write post to copy it.".$note;
    }

    /**
     * Length as Telegram counts it: tags removed, entities decoded, in UTF-16 units (an emoji counts as 2).
     */
    public static function telegramLength(string $html): int
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @return list<string>
     */
    private static function photoLines(Post $post): array
    {
        $urls = $post->imageUrls();
        $link = fn (string $url, string $label): string => '<a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$label.'</a>';

        return match (count($urls)) {
            0 => [],
            1 => ['', '🖼 '.$link($urls[0], 'Photo').' (posted with the text)'],
            default => ['', '🖼 '.count($urls).' photos'.($post->socialAccount?->platform === SocialPlatform::FacebookPage
                ? ' (Facebook posts the first): '
                : ', posted as a carousel: ').implode(' ', array_map($link, $urls, range(1, count($urls))))],
        };
    }

    private static function e(string $text): string
    {
        // Only <, > and & need escaping; Telegram's HTML mode does not know entities such as &apos;.
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
