<?php

namespace App\Filament\Resources\AffiliateAccounts\Tables;

use App\Enums\AffiliateProgram;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AffiliateAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('program')
                    ->badge(),
                TextColumn::make('tracking_id')
                    ->label('Tracking ID')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products')
                    ->sortable(),
                TextColumn::make('campaigns_count')
                    ->counts('campaigns')
                    ->label('Campaigns')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('program')
                    ->options(AffiliateProgram::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
