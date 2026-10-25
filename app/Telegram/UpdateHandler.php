<?php

namespace App\Telegram;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Content\ManualContentParser;
use App\Enums\PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\RegenerateDraft;
use App\Models\Post;
use App\Models\Recommendation;
use App\Telegram\Exceptions\PhotoException;
use App\Telegram\Exceptions\TelegramException;
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
        private readonly AlbumCollector $albums,
        private readonly RecommendationAssistant $recommendations,
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

        if (isset($message['media_group_id']) && $photo) {
            $this->albumPhoto($message, null, $text, $chatId);

            return;
        }

        // A Threads post where someone asked for a recommendation.
        if (! $photo && RecommendationAssistant::isThreadsLink($text)) {
            $this->recommendations->handle($chatId, (int) $message['message_id'], $text);

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

        // Buttons on a recommendation (a reply you post yourself on someone's Threads post).
        if (in_array($action, ['recp', 'recd', 'recl', 'recs'], true)) {
            $messageId = (int) ($query['message']['message_id'] ?? 0);
            $this->answer($query['id'], match ($action) {
                'recp' => $this->recommendations->pick($chatId, $messageId, (string) $id),
                'recd' => $this->recommendations->done($chatId, $messageId, (string) $id),
                'recl' => $this->recommendations->link($chatId, $messageId, (string) $id),
                'recs' => $this->recommendations->skip($chatId, $messageId, (string) $id),
            });

            return;
        }

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
            'disc' => $this->toggleDisclosure($post),
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

        $recommendation = $post === null ? Recommendation::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', (int) $message['reply_to_message']['message_id'])
            ->first() : null;

        if ($recommendation !== null) {
            $text === ''
                ? $this->recommendations->needsText($chatId, (int) $message['message_id'])
                : $this->recommendations->reply($recommendation, $text, $chatId, (int) $message['message_id'], $message['entities'] ?? []);

            return;
        }

        // A reply to anything that is not a post (e.g. "What is it called?") is read as a new message.
        if ($post === null) {
            $this->handlePlainMessage($message);

            return;
        }

        if (isset($message['media_group_id']) && PhotoStore::hasPhoto($message)) {
            $this->albumPhoto($message, $post, $text, $chatId);

            return;
        }

        $this->forPost($post, $message, $text, $chatId, (int) $message['message_id']);
    }

    /**
     * One photo of an album: gathered, and answered once the whole album is in (settleAlbum).
     *
     * @param  array<string, mixed>  $message
     */
    private function albumPhoto(array $message, ?Post $post, string $text, string $chatId): void
    {
        $entities = $message['caption_entities'] ?? [];
        $isLink = $post === null && $text !== '' && ! ManualContentParser::looksLikeAnswer($text) && LinkIntake::containsLink($text, $entities);

        $this->albums->record((string) $message['media_group_id'], $message, $chatId, $post, $text, $isLink);

        if ($isLink) {
            $this->links->handle($chatId, (int) $message['message_id'], $text, $entities);
        }
    }

    /**
     * Puts a gathered album on its post and answers once: your caption as the post text, or
     * "N photos saved". With no post to go to, one hint, and the photos are removed again.
     */
    public function settleAlbum(string $albumId): void
    {
        $state = $this->albums->take($albumId);

        if ($state === null || ! isset($state['chat_id'])) {
            return;
        }

        $chatId = (string) $state['chat_id'];
        $photos = $state['photos'] ?? [];
        ksort($photos);
        $urls = array_values(array_filter(array_column($photos, 'url')));
        $caption = (string) ($state['caption'] ?? '');
        $replyTo = (int) ($state['caption_message'] ?? $state['reply_to'] ?? $state['last_message']);
        $target = isset($state['post_id']) ? Post::find($state['post_id']) : null;

        // Sent without replying: with one post waiting and nothing else said, it is for that one.
        if ($target === null && ! isset($state['reply_to']) && ! ($state['link'] ?? false)) {
            $waiting = Post::query()->where('status', PostStatus::Pending)->get();
            $target = $waiting->count() === 1 && ($caption === '' || ManualContentParser::looksLikeAnswer($caption)) ? $waiting->first() : null;
        }

        if ($target === null) {
            self::discard($urls);

            if (! ($state['link'] ?? false) && ! ($state['hinted'] ?? false)) {
                $this->safely(fn () => $this->bot->sendMessage($chatId, e('Reply to a post\'s message with your photos (long-press it, then Reply), so I know which post they are for.'), replyToMessageId: $replyTo));
            }

            $this->albums->remember($albumId, ['hinted' => true]);

            return;
        }

        $previous = [...$target->imageUrls(), $target->product?->image_url];

        // Only while the post is still unapproved, so an approval made meanwhile cannot change.
        $changed = $urls === [] ? 1 : Post::query()
            ->whereKey($target->id)
            ->whereIn('status', [PostStatus::Pending, PostStatus::Draft, PostStatus::Rejected])
            ->update(['images' => json_encode($urls), 'image_url' => $urls[0], 'updated_at' => now()]);

        if ($changed === 0) {
            self::discard($urls);

            if (! ($state['refused'] ?? false)) {
                $status = $target->fresh()->status->value;
                $this->safely(fn () => $this->bot->sendMessage($chatId, e("Post #{$target->id} is already {$status}; photos can only be added before it is approved."), replyToMessageId: $replyTo));
            }

            $this->albums->remember($albumId, ['refused' => true]);

            return;
        }

        if ($urls !== []) {
            $target->product?->update(['image_url' => $urls[0]]);

            foreach (array_unique(array_filter($previous)) as $old) {
                PhotoStore::deleteIfUnused($old);
            }
        }

        // Photos that could not be used, each reported once.
        $reported = $state['reported'] ?? [];
        $newFailures = [];

        foreach ($photos as $messageId => $photo) {
            if ($photo['error'] !== null && ! in_array($messageId, $reported, true)) {
                $newFailures[$messageId] = $photo['error'];
            }
        }

        if ($newFailures !== []) {
            $count = count($newFailures);
            $reason = reset($newFailures);
            $this->safely(fn () => $this->bot->sendMessage($chatId, e(($count === 1 ? '1 photo was skipped: ' : "{$count} photos were skipped: ").$reason), replyToMessageId: $replyTo));
        }

        $post = $target->fresh();
        $flags = ['reported' => [...$reported, ...array_keys($newFailures)]];

        if ($caption !== '' && ! ($state['caption_applied'] ?? false)) {
            $this->useText($post, $caption, $chatId, $replyTo, photoSaved: $urls !== []);
            $flags += ['caption_applied' => true, 'shown' => true];
        } elseif ($urls !== [] && ! ($state['shown'] ?? false)) {
            $count = count($urls);
            $this->chat->continueBelow($post, $replyTo, $post->status === PostStatus::Pending
                ? "🖼 {$count} ".str('photo')->plural($count).' saved. Now reply to this message with your text.'
                : "🖼 {$count} ".str('photo')->plural($count).' attached.');
            $flags['shown'] = true;
        } elseif ($urls !== []) {
            $this->chat->refresh($post);
        }

        $this->albums->remember($albumId, $flags);
    }

    /**
     * @param  list<string>  $urls
     */
    private static function discard(array $urls): void
    {
        foreach ($urls as $url) {
            PhotoStore::deleteIfUnused($url);
        }
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

        $this->useText($post, $text, $chatId, $replyTo, $photoSaved);
    }

    /**
     * Text for a post: your post text, or feedback for an AI draft.
     */
    private function useText(Post $post, string $text, string $chatId, int $replyTo, bool $photoSaved = false): void
    {
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

        $previous = [...$post->imageUrls(), $post->product?->image_url];

        // The product keeps your latest photo too, so its next post starts with it. Posts that
        // already exist keep their own photo.
        $post->update(['image_url' => $url, 'images' => [$url]]);
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
     * Adds the account's disclosure under the link, or takes it out again. Only before approval,
     * and only if the text is still what the button was shown for.
     */
    private function toggleDisclosure(Post $post): string
    {
        $disclosure = DraftMessage::disclosure($post);

        if ($disclosure === null) {
            return 'This account has no disclosure line. Set one in the admin under the account\'s persona.';
        }

        $reply = (string) $post->link_reply_content;
        $included = DraftMessage::hasDisclosure($post);
        $new = $included
            ? trim(str_ireplace(["\n\n{$disclosure}", $disclosure], '', $reply))
            : rtrim($reply)."\n\n".$disclosure;

        $changed = Post::query()
            ->whereKey($post->id)
            ->where('status', PostStatus::Draft)
            ->where('link_reply_content', $reply)
            ->update(['link_reply_content' => $new, 'updated_at' => now()]);

        if ($changed === 0) {
            $status = $post->fresh()->status;

            return $status === PostStatus::Draft
                ? 'The draft changed meanwhile; tap again.'
                : "Already {$status->value}; the disclosure can only change before approval.";
        }

        $this->show($post->fresh());

        return $included ? 'Disclosure removed.' : 'Disclosure added.';
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
