<?php

namespace App\Telegram;

use App\Content\DraftWriter;
use App\Enums\PostStatus;
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
            return self::pendingText($post);
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

        return match ($post->status) {
            PostStatus::Pending => [[$button('⏭ Skip', 'reject')]],
            PostStatus::Draft => $yours
                ? [[$button('✅ Approve', 'approve'), $button('❌ Reject', 'reject')]]
                : [[$button('✅ Approve', 'approve'), $button('❌ Reject', 'reject'), $button('🔄 Regenerate', 'regen')]],
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

    public static function writtenByYou(Post $post): bool
    {
        return $post->writtenByYou();
    }

    private static function pendingText(Post $post): string
    {
        $intro = implode("\n", [
            "✍️ <b>Your turn: post #{$post->id}</b> for <b>".self::e($post->socialAccount->name).'</b>',
            '🛍 '.self::e($post->product?->name ?? 'No product'),
            '',
            'Reply to this message with your post. Paste Claude\'s whole answer (POST: / REPLY:) or just the post; the link and disclosure are added for you.',
            '',
        ]);

        if ($post->product !== null) {
            $prompt = app(DraftWriter::class)->copyPrompt($post->product, $post->socialAccount);
            $text = $intro."\n<b>Prompt for Claude.ai</b> (tap to copy):\n<pre>".self::e($prompt).'</pre>';

            if (self::telegramLength($text) <= self::MAX_LENGTH) {
                return $text;
            }
        }

        return $intro."\nThe prompt is too long for Telegram; open this post in the admin and use Write post to copy it.";
    }

    /**
     * Length as Telegram counts it: tags removed, entities decoded, in UTF-16 units (an emoji counts as 2).
     */
    private static function telegramLength(string $html): int
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
