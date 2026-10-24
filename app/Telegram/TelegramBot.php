<?php

namespace App\Telegram;

use App\Telegram\Exceptions\TelegramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The Telegram Bot API calls the approval flow needs. The bot token sits in every URL, so it is
 * scrubbed from anything logged or thrown.
 */
class TelegramBot
{
    /**
     * @param  string|null  $token  Another bot's token (a channel's own bot); defaults to the approval bot.
     */
    public function __construct(#[SensitiveParameter] private readonly ?string $token = null) {}

    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    public function approvalChatId(): string
    {
        return (string) config('services.telegram.chat_id');
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard  Rows of inline buttons.
     * @return int The new message's ID.
     */
    public function sendMessage(string $chatId, string $html, ?array $keyboard = null, ?int $replyToMessageId = null): int
    {
        $message = $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard === null ? null : ['inline_keyboard' => $keyboard],
            'reply_parameters' => $replyToMessageId === null ? null : ['message_id' => $replyToMessageId, 'allow_sending_without_reply' => true],
        ], fn ($value) => $value !== null));

        return (int) $message['message_id'];
    }

    /**
     * Replace a message's text. Without a keyboard, its buttons are removed.
     *
     * @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard
     */
    public function editMessageText(string $chatId, int $messageId, string $html, ?array $keyboard = null): void
    {
        try {
            $this->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => $keyboard ?? []],
            ]);
        } catch (TelegramException $e) {
            // Editing to identical content is harmless; Telegram reports it as an error anyway.
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }
        }
    }

    /**
     * A plain-text message (no HTML parsing), for content written for the public.
     *
     * @return int The new message's ID.
     */
    public function sendText(string $chatId, string $text, ?int $replyToMessageId = null, bool $linkPreview = false): int
    {
        $message = $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => ! $linkPreview],
            'reply_parameters' => $replyToMessageId === null ? null : ['message_id' => $replyToMessageId],
        ], fn ($value) => $value !== null));

        return (int) $message['message_id'];
    }

    /**
     * A photo for a channel post. Telegram fetches the image from the URL itself.
     *
     * @return int The new message's ID.
     */
    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null, ?int $replyToMessageId = null): int
    {
        $message = $this->call('sendPhoto', array_filter([
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'reply_parameters' => $replyToMessageId === null ? null : ['message_id' => $replyToMessageId],
        ], fn ($value) => $value !== null));

        return (int) $message['message_id'];
    }

    /**
     * @return array{file_id: string, file_path?: string, file_size?: int}
     */
    public function getFile(string $fileId): array
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * The bytes of a file sent to the bot, from the path getFile returned.
     */
    public function downloadFile(string $filePath, int $maxBytes): string
    {
        $token = (string) ($this->token ?? config('services.telegram.bot_token'));
        $url = rtrim((string) config('services.telegram.api_base_url'), '/')."/file/bot{$token}/".ltrim($filePath, '/');

        try {
            $response = Http::timeout(30)->connectTimeout(5)->get($url);
        } catch (ConnectionException $e) {
            Log::warning('Telegram file download failed', ['error' => str_replace($token, '[token]', $e->getMessage())]);

            throw new TelegramException('Could not download the file from Telegram.', retryable: true);
        }

        if (! $response->successful() || strlen($response->body()) > $maxBytes) {
            throw new TelegramException($response->successful() ? 'The file is too large.' : "Telegram file download failed: HTTP {$response->status()}");
        }

        return $response->body();
    }

    /**
     * @return array<string, mixed>
     */
    public function getChat(string $chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackQueryId, 'text' => $text]));
    }

    /**
     * Long-poll for updates (only works while no webhook is set).
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeoutSeconds = 25): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeoutSeconds,
            'allowed_updates' => ['message', 'callback_query'],
        ], httpTimeout: $timeoutSeconds + 10);
    }

    public function setWebhook(string $url): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => (string) config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(string $method, array $params = [], int $httpTimeout = 15): mixed
    {
        $token = (string) ($this->token ?? config('services.telegram.bot_token'));

        if ($token === '') {
            throw new TelegramException('Set TELEGRAM_BOT_TOKEN in .env to use Telegram approvals.');
        }

        $url = rtrim((string) config('services.telegram.api_base_url'), '/')."/bot{$token}/{$method}";

        try {
            $response = Http::acceptJson()->timeout($httpTimeout)->connectTimeout(5)->post($url, $params);
        } catch (ConnectionException $e) {
            Log::warning('Telegram API unreachable', ['method' => $method, 'error' => str_replace($token, '[token]', $e->getMessage())]);

            throw new TelegramException('Could not reach Telegram.', retryable: true);
        }

        $json = $response->json();

        if (is_array($json) && ($json['ok'] ?? false) === true) {
            return $json['result'] ?? null;
        }

        $status = (int) ($json['error_code'] ?? $response->status());
        $description = str_replace($token, '[token]', (string) ($json['description'] ?? "HTTP {$response->status()}"));
        $retryAfter = isset($json['parameters']['retry_after']) ? (int) $json['parameters']['retry_after'] : null;

        if (! str_contains($description, 'message is not modified')) {
            Log::warning('Telegram API error', ['method' => $method, 'status' => $status, 'description' => $description]);
        }

        throw new TelegramException(
            "Telegram {$method} failed: {$description}",
            retryable: $status === 429 || $status >= 500,
            retryAfter: $retryAfter,
            status: $status,
        );
    }
}
