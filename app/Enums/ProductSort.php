<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductSort: string implements HasLabel
{
    case Relevance = 'relevance';
    case BestSelling = 'best_selling';
    case HighestCommission = 'highest_commission';

    public function getLabel(): string
    {
        return match ($this) {
            self::Relevance => 'Most relevant',
            self::BestSelling => 'Best selling',
            self::HighestCommission => 'Highest commission',
        };
    }
}
