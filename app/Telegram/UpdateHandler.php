<?php

namespace App\Telegram;

use App\Enums\PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\RegenerateDraft;
use App\Models\Post;
use App\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns Telegram updates (button taps and replies) into approval decisions. Updates are untrusted
 * input: only the configured chat, and optionally only listed users, can act on drafts.
 */
class UpdateHandler
{
    public function __construct(private readonly TelegramBot $bot) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['message']['reply_to_message'])) {
            $this->handleReply($update['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function handleCallback(array $query): void
    {
        $chatId = (string) ($query['message']['chat']['id'] ?? '');

        if (! $this->authorized($chatId, $query['from'] ?? [])) {
            $this->answer($query['id'], 'You are not allowed to approve posts.');

            return;
        }

        [$action, $id] = array_pad(explode(':', (string) ($query['data'] ?? ''), 2), 2, null);
        $post = Post::find((int) $id);

        if ($post === null) {
            $this->answer($query['id'], 'That draft no longer exists.');

            return;
        }

        $by = $this->name($query['from']);

        $answer = match ($action) {
            'approve' => $this->transition($post, [PostStatus::Draft], PostStatus::Approved, $by, fn () => PublishPost::dispatch($post))
                ? 'Approved. Publishing...' : "Already {$post->fresh()->status->value}.",
            'reject' => $this->transition($post, [PostStatus::Draft], PostStatus::Rejected, $by)
                ? 'Rejected.' : "Already {$post->fresh()->status->value}.",
            'retry' => $this->transition($post, [PostStatus::Failed], PostStatus::Approved, $by, fn () => PublishPost::dispatch($post))
                ? 'Publishing again...' : "Already {$post->fresh()->status->value}.",
            'regen' => $this->regenerate($post, null) ? 'Writing a new version...' : 'Only drafts and rejected posts can be rewritten.',
            default => 'Unknown action.',
        };

        $this->answer($query['id'], $answer);
    }

    /**
     * A text reply to a draft's message is feedback for a rewrite.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleReply(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '' || ! $this->authorized($chatId, $message['from'] ?? [])) {
            return;
        }

        $post = Post::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', (int) $message['reply_to_message']['message_id'])
            ->first();

        if ($post !== null && ! $this->regenerate($post, $text)) {
            $this->safely(fn () => $this->bot->sendMessage($chatId, 'Only drafts and rejected posts can be rewritten.', replyToMessageId: (int) $message['message_id']));
        }
    }

    /**
     * Move the post between states in one atomic update, so a double tap acts only once.
     *
     * @param  list<PostStatus>  $from
     */
    private function transition(Post $post, array $from, PostStatus $to, string $by, ?callable $then = null): bool
    {
        $changed = Post::query()
            ->whereKey($post->id)
            ->whereIn('status', $from)
            ->update(['status' => $to, 'approved_by' => $by, 'approved_at' => now(), 'updated_at' => now()]);

        if ($changed === 0) {
            return false;
        }

        $post->refresh();
        $this->show($post);

        if ($then !== null) {
            $then();
        }

        return true;
    }

    private function regenerate(Post $post, ?string $feedback): bool
    {
        if (! in_array($post->status, [PostStatus::Draft, PostStatus::Rejected], true)) {
            return false;
        }

        // Buttons go away while rewriting, so nobody approves the version being replaced.
        $this->show($post, $feedback === null ? '🔄 Writing a new version...' : '🔄 Rewriting with your notes: '.Str::limit($feedback, 300), buttons: false);
        RegenerateDraft::dispatch($post, $feedback);

        return true;
    }

    /**
     * Update the approval message right away, so the chat reflects the tap before any job runs.
     */
    private function show(Post $post, ?string $note = null, bool $buttons = true): void
    {
        if ($post->telegram_message_id === null) {
            return;
        }

        $this->safely(fn () => $this->bot->editMessageText(
            (string) $post->telegram_chat_id,
            $post->telegram_message_id,
            DraftMessage::text($post, $note),
            $buttons ? DraftMessage::keyboard($post) : null,
        ));
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function authorized(string $chatId, array $from): bool
    {
        $allowedUsers = (array) config('services.telegram.allowed_user_ids');

        $ok = $chatId !== '' && $chatId === $this->bot->approvalChatId()
            && ($allowedUsers === [] || in_array((string) ($from['id'] ?? ''), $allowedUsers, true));

        if (! $ok) {
            Log::warning('Ignored Telegram update from an unauthorized chat or user', ['chat_id' => $chatId, 'user_id' => $from['id'] ?? null]);
        }

        return $ok;
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function name(array $from): string
    {
        return isset($from['username']) ? '@'.$from['username'] : (string) ($from['first_name'] ?? 'someone');
    }

    private function answer(string $callbackQueryId, string $text): void
    {
        $this->safely(fn () => $this->bot->answerCallbackQuery($callbackQueryId, $text));
    }

    /**
     * Chat updates are courtesy; a Telegram hiccup must not undo a decision already saved.
     */
    private function safely(callable $call): void
    {
        try {
            $call();
        } catch (TelegramException $e) {
            Log::warning('Telegram update failed', ['error' => $e->getMessage()]);
        }
    }
}
