<?php

namespace App\Telegram;

use App\Enums\PostStatus;
use App\Models\Post;

/**
 * How a post looks in the Telegram approval chat, and which buttons it offers in each state.
 */
class DraftMessage
{
    public static function text(Post $post, ?string $note = null): string
    {
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

        if (filled($post->generation['model'] ?? null)) {
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

        return match ($post->status) {
            PostStatus::Draft => [[$button('✅ Approve', 'approve'), $button('❌ Reject', 'reject'), $button('🔄 Regenerate', 'regen')]],
            PostStatus::Rejected => [[$button('🔄 Regenerate', 'regen')]],
            PostStatus::Failed => [[$button('🔁 Retry publishing', 'retry')]],
            PostStatus::Approved, PostStatus::Published => null,
        };
    }

    private static function status(Post $post): string
    {
        return match ($post->status) {
            PostStatus::Draft => 'Tap a button, or reply to this message with feedback to get a rewrite.',
            PostStatus::Approved => "✅ Approved by {$post->approved_by}. Publishing...",
            PostStatus::Published => '✅ Published'.($post->permalink ? ": {$post->permalink}" : '.'),
            PostStatus::Rejected => "❌ Rejected by {$post->approved_by}. Reply with feedback or tap Regenerate to try again.",
            PostStatus::Failed => '⚠️ Publishing failed: '.($post->last_error ?? 'unknown error'),
        };
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
