<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('affiliateAccount'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record): string => $record->affiliateAccount->name),
                TextColumn::make('status')
                    ->badge(),
                IconColumn::make('used_personally')
                    ->label('Used it')
                    ->boolean(),
                TextColumn::make('posts_count')
                    ->counts('posts')
                    ->label('Posts')
                    ->sortable(),
                TextColumn::make('affiliate_link')
                    ->label('Link')
                    ->limit(30)
                    ->url(fn (Product $record): string => $record->affiliate_link, shouldOpenInNewTab: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProductStatus::class),
                SelectFilter::make('affiliate_account_id')
                    ->label('Affiliate account')
                    ->relationship('affiliateAccount', 'name'),
                TernaryFilter::make('used_personally')
                    ->label('Used it'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
