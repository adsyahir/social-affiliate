<?php

namespace App\Telegram;

use App\Models\Post;
use App\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a post's approval message where you are in the chat.
 */
class ApprovalChat
{
    public function __construct(private readonly TelegramBot $bot) {}

    /**
     * The post (and its buttons) carries on in a new message under the given message, and the old
     * one points down to it. Later updates edit the new message.
     */
    public function continueBelow(Post $post, int $replyTo, ?string $note = null, bool $buttons = true): void
    {
        $chatId = (string) ($post->telegram_chat_id ?? $this->bot->approvalChatId());
        $oldMessageId = $post->telegram_message_id;
        $keyboard = $buttons ? DraftMessage::keyboard($post) : null;

        try {
            $newMessageId = $this->bot->sendMessage($chatId, DraftMessage::text($post, $note), $keyboard, $replyTo);
        } catch (TelegramException $e) {
            Log::warning('Telegram update failed', ['error' => $e->getMessage()]);

            // Keep the old message up to date instead.
            if ($oldMessageId !== null) {
                $this->safely(fn () => $this->bot->editMessageText($chatId, $oldMessageId, DraftMessage::text($post, $note), $keyboard));
            }

            return;
        }

        $post->forceFill(['telegram_chat_id' => $chatId, 'telegram_message_id' => $newMessageId])->saveQuietly();

        if ($oldMessageId !== null) {
            $this->safely(fn () => $this->bot->editMessageText($chatId, $oldMessageId, "<i>Post #{$post->id} continues below ⬇️</i>"));
        }
    }

    /**
     * Brings the post's current message up to date where it is (e.g. more photos of an album arrived).
     */
    public function refresh(Post $post, ?string $note = null): void
    {
        if ($post->telegram_message_id === null) {
            return;
        }

        $this->safely(fn () => $this->bot->editMessageText(
            (string) $post->telegram_chat_id,
            $post->telegram_message_id,
            DraftMessage::text($post, $note),
            DraftMessage::keyboard($post),
        ));
    }

    private function safely(callable $call): void
    {
        try {
            $call();
        } catch (TelegramException $e) {
            Log::warning('Telegram update failed', ['error' => $e->getMessage()]);
        }
    }
}
