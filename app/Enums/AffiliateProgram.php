<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AffiliateProgram: string implements HasLabel
{
    case Amazon = 'amazon';
    case Shopee = 'shopee';
    case TikTokShop = 'tiktok_shop';

    public function getLabel(): string
    {
        return match ($this) {
            self::Amazon => 'Amazon',
            self::Shopee => 'Shopee',
            self::TikTokShop => 'TikTok Shop',
        };
    }
}
