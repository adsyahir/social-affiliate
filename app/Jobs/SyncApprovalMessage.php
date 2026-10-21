<?php

namespace App\Jobs;

use App\Models\Post;
use App\Telegram\DraftMessage;
use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a post's approval message to Telegram, or brings the existing one up to date.
 */
class SyncApprovalMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Post $post,
        public ?string $note = null,
    ) {}

    public function handle(TelegramBot $bot): void
    {
        if (! $bot->configured()) {
            return;
        }

        $text = DraftMessage::text($this->post, $this->note);
        $keyboard = DraftMessage::keyboard($this->post);

        try {
            if ($this->post->telegram_message_id !== null) {
                $bot->editMessageText((string) $this->post->telegram_chat_id, $this->post->telegram_message_id, $text, $keyboard);

                return;
            }

            $chatId = $bot->approvalChatId();
            $messageId = $bot->sendMessage($chatId, $text, $keyboard);
            $this->post->forceFill(['telegram_chat_id' => $chatId, 'telegram_message_id' => $messageId])->saveQuietly();
        } catch (TelegramException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($e->retryAfter ?? 30);

                return;
            }

            $this->fail($e);
        }
    }
}
