<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SocialPlatform: string implements HasLabel
{
    case Threads = 'threads';
    case FacebookPage = 'facebook_page';
    case TelegramChannel = 'telegram_channel';
    case Instagram = 'instagram';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case TikTok = 'tiktok';

    public function getLabel(): string
    {
        return match ($this) {
            self::Threads => 'Threads',
            self::FacebookPage => 'Facebook Page',
            self::TelegramChannel => 'Telegram channel',
            self::Instagram => 'Instagram',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X',
            self::TikTok => 'TikTok',
        };
    }
}
