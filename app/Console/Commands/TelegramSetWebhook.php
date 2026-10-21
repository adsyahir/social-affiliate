<?php

namespace App\Console\Commands;

use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook
        {url? : Public HTTPS URL of the webhook; defaults to APP_URL/telegram/webhook}';

    protected $description = 'Tell Telegram where to deliver approval button taps and replies';

    public function handle(TelegramBot $bot): int
    {
        // Built from APP_URL: on the command line there is no request to take the host from.
        $url = $this->argument('url') ?: rtrim((string) config('app.url'), '/').route('telegram.webhook', absolute: false);

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram only delivers to HTTPS URLs: {$url}");

            return self::FAILURE;
        }

        if (blank(config('services.telegram.webhook_secret'))) {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET first (letters, numbers, _ and -), e.g. '.bin2hex(random_bytes(16)));

            return self::FAILURE;
        }

        try {
            $bot->setWebhook($url);
        } catch (TelegramException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Webhook set: {$url}");

        return self::SUCCESS;
    }
}
