<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who writes an account's scheduled posts.
 */
enum ContentSource: string implements HasLabel
{
    case Ai = 'ai';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ai => 'AI writes them (Claude API)',
            self::Manual => 'I write them (paste my own text)',
        };
    }
}
