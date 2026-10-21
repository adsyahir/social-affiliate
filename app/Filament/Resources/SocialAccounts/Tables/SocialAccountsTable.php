<?php

namespace App\Filament\Resources\SocialAccounts\Tables;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SocialAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('platform')
                    ->badge(),
                TextColumn::make('persona.language')
                    ->label('Language')
                    ->placeholder('-'),
                IconColumn::make('has_token')
                    ->label('Token')
                    ->state(fn (SocialAccount $record): bool => filled($record->access_token))
                    ->boolean(),
                TextColumn::make('token_expires_at')
                    ->label('Token expires')
                    ->since()
                    ->dateTimeTooltip()
                    ->color(fn (?CarbonInterface $state): ?string => $state?->lt(now()->addDays(7)) ? 'danger' : null)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('campaigns.name')
                    ->label('Campaigns')
                    ->badge()
                    ->placeholder('None'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->options(SocialPlatform::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
