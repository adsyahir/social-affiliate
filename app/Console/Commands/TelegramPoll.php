<?php

namespace App\Console\Commands;

use App\Telegram\Exceptions\TelegramException;
use App\Telegram\TelegramBot;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll
        {--once : Fetch one batch of updates and stop}';

    protected $description = 'Receive Telegram approvals by polling, for local development or servers without HTTPS';

    private const OFFSET_KEY = 'telegram.update_offset';

    public function handle(TelegramBot $bot, UpdateHandler $handler): int
    {
        $this->info('Listening for Telegram updates. Polling stops working while a webhook is set.');

        do {
            try {
                $updates = $bot->getUpdates((int) Cache::get(self::OFFSET_KEY, 0), $this->option('once') ? 0 : 25);
            } catch (TelegramException $e) {
                $this->error($e->getMessage());

                if (! $e->retryable && str_contains($e->getMessage(), 'webhook')) {
                    $this->line('A webhook is set. Remove it with the deleteWebhook Bot API call to poll instead.');

                    return self::FAILURE;
                }

                Sleep::for($e->retryAfter ?? 5)->seconds();

                continue;
            }

            foreach ($updates as $update) {
                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    $this->error("Update {$update['update_id']} failed: {$e->getMessage()}");
                    report($e);
                }

                // Move past every update, handled or not, so a bad one is not replayed forever.
                Cache::forever(self::OFFSET_KEY, (int) $update['update_id'] + 1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
