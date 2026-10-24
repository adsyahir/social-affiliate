<?php

namespace App\Telegram;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Content\ManualContentParser;
use App\Enums\PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\RegenerateDraft;
use App\Models\Post;
use App\Telegram\Exceptions\PhotoException;
use App\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns Telegram updates (button taps and replies) into approval decisions. Updates are untrusted
 * input: only the configured chat, and optionally only listed users, can act on drafts.
 */
class UpdateHandler
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly DraftWriter $writer,
        private readonly LinkIntake $links,
        private readonly ApprovalChat $chat,
        private readonly PhotoStore $photos,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['message']['reply_to_message'])) {
            $this->handleReply($update['message']);
        } elseif (isset($update['message'])) {
            $this->handlePlainMessage($update['message']);
        }
    }

    /**
     * Text sent without replying cannot be matched to a post, so say how to do it instead of staying silent.
     *
     * @param  array<string, mixed>  $message
     */
    private function handlePlainMessage(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = self::text($message);
        $photo = PhotoStore::hasPhoto($message);

        if (($text === '' && ! $photo) || ! $this->authorized($chatId, $message['from'] ?? [])) {
            return;
        }

        $waiting = Post::query()->where('status', PostStatus::Pending)->orderBy('id')->get();

        // A photo (without other words) or a "POST:" answer sent to the wrong message: with only one
        // post waiting, it is clearly for that one. A photo captioned with a link starts a new post instead.
        if ($waiting->count() === 1 && (ManualContentParser::looksLikeAnswer($text) || ($photo && $text === ''))) {
            $this->forPost($waiting->first(), $message, $text, $chatId, (int) $message['message_id']);

            return;
        }

        $entities = $message['entities'] ?? $message['caption_entities'] ?? [];

        if ($text !== '' && LinkIntake::containsLink($text, $entities)) {
            $this->links->handle($chatId, (int) $message['message_id'], $text, $entities);

            return;
        }

        $hint = 'Send a Shopee or Amazon product link to start a post. To give a post its text or a photo, reply to its message (long-press it, then Reply).';

        if ($waiting->isNotEmpty()) {
            $hint .= ' Waiting for your text: '.$waiting->map(fn (Post $post): string => "#{$post->id}")->implode(', ').'.';
        }

        $this->safely(fn () => $this->bot->sendMessage($chatId, e($hint), replyToMessageId: (int) $message['message_id']));
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

        // Buttons under a product link you sent: which account, then who writes it.
        if ($action === 'start' || $action === 'write') {
            $messageId = (int) ($query['message']['message_id'] ?? 0);
            $this->answer($query['id'], $action === 'start'
                ? $this->links->choose($chatId, $messageId, (string) $id)
                : $this->links->write($chatId, $messageId, (string) $id));

            return;
        }

        $post = Post::find((int) $id);

        if ($post === null) {
            $this->answer($query['id'], 'That draft no longer exists.');

            return;
        }

        $by = $this->name($query['from']);

        $answer = match ($action) {
            'approve' => $this->transition($post, [PostStatus::Draft], PostStatus::Approved, $by, fn () => PublishPost::dispatch($post))
                ? 'Approved. Publishing...' : "Already {$post->fresh()->status->value}.",
            'reject' => $this->transition($post, [PostStatus::Draft, PostStatus::Pending], PostStatus::Rejected, $by)
                ? 'Rejected.' : "Already {$post->fresh()->status->value}.",
            'retry' => $this->transition($post, [PostStatus::Failed], PostStatus::Approved, $by, fn () => PublishPost::dispatch($post))
                ? 'Publishing again...' : "Already {$post->fresh()->status->value}.",
            'regen' => $this->regenerate($post, null) ? 'Writing a new version...' : ($post->writtenByYou()
                ? 'This is your own text. Reply with new text to replace it.'
                : 'Only drafts and rejected posts can be rewritten.'),
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
        $text = self::text($message);

        if (($text === '' && ! PhotoStore::hasPhoto($message)) || ! $this->authorized($chatId, $message['from'] ?? [])) {
            return;
        }

        $post = Post::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', (int) $message['reply_to_message']['message_id'])
            ->first();

        // A reply to anything that is not a post (e.g. "What is it called?") is read as a new message.
        if ($post === null) {
            $this->handlePlainMessage($message);

            return;
        }

        $this->forPost($post, $message, $text, $chatId, (int) $message['message_id']);
    }

    /**
     * Text and/or a photo meant for this post. A photo is attached first; the text (a photo's
     * caption counts) is then your post text, or feedback for an AI draft.
     *
     * @param  array<string, mixed>  $message
     */
    private function forPost(Post $post, array $message, string $text, string $chatId, int $replyTo): void
    {
        $photoSaved = false;

        if (PhotoStore::hasPhoto($message)) {
            // An album arrives as one update per photo; the first one is used.
            if (isset($message['media_group_id']) && ! Cache::add("telegram-album:{$message['media_group_id']}", true, now()->addMinutes(10))) {
                return;
            }

            if (! $this->attachPhoto($post, $message, $chatId, $replyTo)) {
                return;
            }

            $photoSaved = true;

            if ($text === '') {
                $this->chat->continueBelow($post->fresh(), $replyTo, $post->status === PostStatus::Pending
                    ? '🖼 Photo saved. Now reply to this message with your text.'
                    : '🖼 Photo attached.');

                return;
            }
        }

        // Posts you write yourself: the reply is the new text, not feedback for the AI.
        if ($post->status === PostStatus::Pending || DraftMessage::writtenByYou($post)) {
            $this->useYourText($post, $text, $chatId, $replyTo, $photoSaved);

            return;
        }

        if (! $this->regenerate($post, $text, $replyTo)) {
            $this->safely(fn () => $this->bot->sendMessage($chatId, 'Only drafts and rejected posts can be rewritten.', replyToMessageId: $replyTo));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function attachPhoto(Post $post, array $message, string $chatId, int $replyTo): bool
    {
        if (! in_array($post->status, [PostStatus::Pending, PostStatus::Draft, PostStatus::Rejected], true)) {
            $this->safely(fn () => $this->bot->sendMessage($chatId, e("Post #{$post->id} is already {$post->status->value}; a photo can only be added before it is approved."), replyToMessageId: $replyTo));

            return false;
        }

        try {
            $url = $this->photos->save($message);
        } catch (PhotoException $e) {
            $this->safely(fn () => $this->bot->sendMessage($chatId, e($e->getMessage()), replyToMessageId: $replyTo));

            return false;
        }

        $previous = [$post->image_url, $post->product?->image_url];

        // The product keeps your latest photo too, so its next post starts with it. Posts that
        // already exist keep their own photo.
        $post->update(['image_url' => $url]);
        $post->product?->update(['image_url' => $url]);

        foreach (array_unique(array_filter($previous)) as $old) {
            PhotoStore::deleteIfUnused($old);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function text(array $message): string
    {
        return trim((string) ($message['text'] ?? $message['caption'] ?? ''));
    }

    private function useYourText(Post $post, string $text, string $chatId, int $replyTo, bool $photoSaved = false): void
    {
        try {
            $this->writer->fillFromText($post, $text);
        } catch (ContentGenerationException $e) {
            $message = $e->getMessage().($photoSaved ? ' (The photo was saved; reply again with the text.)' : '');
            $this->safely(fn () => $this->bot->sendMessage($chatId, e($message), replyToMessageId: $replyTo));

            return;
        }

        $this->chat->continueBelow($post->fresh(), $replyTo, '✏️ Your new text.');
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

    /**
     * @param  int|null  $replyTo  your reply, when the rewrite was asked for by replying
     */
    private function regenerate(Post $post, ?string $feedback, ?int $replyTo = null): bool
    {
        if (! in_array($post->status, [PostStatus::Draft, PostStatus::Rejected], true) || $post->writtenByYou()) {
            return false;
        }

        // Buttons go away while rewriting, so nobody approves the version being replaced.
        $note = $feedback === null ? '🔄 Writing a new version...' : '🔄 Rewriting with your notes: '.Str::limit($feedback, 300);
        $replyTo === null ? $this->show($post, $note, buttons: false) : $this->chat->continueBelow($post, $replyTo, $note, buttons: false);
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
