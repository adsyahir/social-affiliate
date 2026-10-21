<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('affiliate_account_id')
                    ->label('Affiliate account')
                    ->relationship('affiliateAccount', 'name')
                    ->preload()
                    ->required()
                    ->helperText('Products are picked from this account only.'),
                Select::make('socialAccounts')
                    ->label('Social accounts')
                    ->relationship('socialAccounts', 'name')
                    ->multiple()
                    ->preload()
                    ->required()
                    ->helperText('Posts from this campaign go to these accounts and nowhere else.')
                    ->columnSpanFull(),
                TextInput::make('posts_per_day')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(24)
                    ->default(1)
                    ->required(),
                Toggle::make('active')
                    ->default(true)
                    ->inline(false),
            ]);
    }
}
