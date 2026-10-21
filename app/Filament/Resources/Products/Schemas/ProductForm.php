<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('affiliate_account_id')
                            ->label('Affiliate account')
                            ->relationship('affiliateAccount', 'name')
                            ->preload()
                            ->required(),
                        Select::make('status')
                            ->options(ProductStatus::class)
                            ->default(ProductStatus::Available)
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('affiliate_link')
                            ->url()
                            ->required()
                            ->helperText('Only ever posted in the reply, never in the main post. For Shopee without API access, paste the short link from the Shopee Affiliate dashboard.')
                            ->columnSpanFull(),
                        TextInput::make('product_url')
                            ->label('Product page URL')
                            ->url()
                            ->helperText('The normal product page. With API credentials, a tracked link is generated from it for each post.')
                            ->columnSpanFull(),
                        TextInput::make('image_url')
                            ->label('Image URL')
                            ->url()
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->helperText('Facts the AI may use: price, specs, ratings, who it suits.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Your own experience')
                    ->columnSpanFull()
                    ->description('Posts only make first-person claims ("I used it...") when this is on, and only from your notes.')
                    ->schema([
                        Toggle::make('used_personally')
                            ->label('I bought and used this product')
                            ->live(),
                        Textarea::make('review_notes')
                            ->label('Review notes')
                            ->placeholder('Dah guna 2 minggu. Cas laju, tapi casing cepat calar.')
                            ->rows(3)
                            ->visible(fn (Get $get): bool => (bool) $get('used_personally'))
                            ->required(fn (Get $get): bool => (bool) $get('used_personally')),
                    ]),
            ]);
    }
}
