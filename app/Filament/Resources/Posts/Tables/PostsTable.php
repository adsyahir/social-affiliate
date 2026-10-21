<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Enums\PostStatus;
use App\Models\Post;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['socialAccount', 'product', 'latestInsight']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('content')
                    ->label('Post')
                    ->limit(80)
                    ->wrap()
                    ->searchable()
                    ->description(fn (Post $record): string => $record->product?->name ?? 'No product'),
                TextColumn::make('socialAccount.name')
                    ->label('Account'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('latestInsight.views')
                    ->label('Views')
                    ->numeric()
                    ->placeholder('-'),
                TextColumn::make('latestInsight.likes')
                    ->label('Likes')
                    ->numeric()
                    ->placeholder('-'),
                TextColumn::make('generation.model')
                    ->label('Model')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PostStatus::class),
                SelectFilter::make('social_account_id')
                    ->label('Account')
                    ->relationship('socialAccount', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
