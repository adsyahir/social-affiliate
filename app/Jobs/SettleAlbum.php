<?php

namespace App\Jobs;

use App\Telegram\UpdateHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Puts a Telegram album's photos on its post once they have all arrived. See AlbumCollector.
 */
class SettleAlbum implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $albumId) {}

    public function handle(UpdateHandler $handler): void
    {
        $handler->settleAlbum($this->albumId);
    }
}
