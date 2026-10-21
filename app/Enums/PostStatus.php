<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PostStatus: string implements HasColor, HasLabel
{
    /**
     * Waiting for you to provide the text (accounts set to "I write them").
     */
    case Pending = 'pending';
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Published = 'published';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return $this === self::Pending ? 'Waiting for your text' : ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Draft => 'gray',
            self::Approved => 'info',
            self::Rejected => 'warning',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }
}
