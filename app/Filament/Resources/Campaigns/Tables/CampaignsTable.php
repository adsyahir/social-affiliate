<?php

namespace App\Filament\Resources\Campaigns\Tables;

use App\Campaigns\CampaignRunner;
use App\Campaigns\PostingSchedule;
use App\Enums\ProductStatus;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->addSelect([
                    // Same rule as the runner: available, never picked by a run, never drafted.
                    'products_left' => Product::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('products.affiliate_account_id', 'campaigns.affiliate_account_id')
                        ->where('status', ProductStatus::Available)
                        ->whereDoesntHave('posts')
                        ->whereNotIn('products.id', CampaignRun::query()->where('status', CampaignRun::DISPATCHED)->whereNotNull('product_id')->select('product_id')),
                ])
                ->withMax('runs', 'created_at')
                ->withCount(['runs as used_today' => fn (Builder $runs) => $runs
                    ->where('trigger', CampaignRun::SCHEDULE)
                    ->whereIn('status', [CampaignRun::DISPATCHED, CampaignRun::FAILED])
                    ->where('created_at', '>=', app(PostingSchedule::class)->startOfDay(now()))]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('affiliateAccount.name')
                    ->label('Affiliate account'),
                TextColumn::make('socialAccounts.name')
                    ->label('Social accounts')
                    ->badge(),
                TextColumn::make('posts_per_day')
                    ->label('Posts / day')
                    ->sortable(),
                TextColumn::make('products_left')
                    ->label('Products left')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): ?string => $state === 0 ? 'danger' : null),
                TextColumn::make('runs_max_created_at')
                    ->label('Last run')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never'),
                TextColumn::make('next_slot')
                    ->label('Next slot')
                    ->state(fn (Campaign $record): ?string => self::nextSlot($record))
                    ->placeholder('-'),
                ToggleColumn::make('active'),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                Action::make('runNow')
                    ->label('Run now')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Writes drafts for the next product right away, for every account in this campaign. It does not use up one of today\'s scheduled slots.')
                    ->action(function (Campaign $record, CampaignRunner $runner): void {
                        $run = $runner->run($record, CampaignRun::MANUAL);

                        match ($run?->status) {
                            null => Notification::make()->title('No social accounts')->body('Add at least one social account to this campaign.')->danger()->send(),
                            CampaignRun::NO_PRODUCTS => Notification::make()->title('No products left')->body('Import or add products for this campaign\'s affiliate account.')->warning()->send(),
                            default => Notification::make()->title('Writing drafts')->body("For \"{$run->product->name}\", {$run->accounts} ".str('account')->plural($run->accounts).'. They arrive in Telegram for approval.')->success()->send(),
                        };
                    }),
                EditAction::make(),
            ]);
    }

    private static function nextSlot(Campaign $campaign): ?string
    {
        if (! $campaign->active) {
            return 'Paused';
        }

        $schedule = app(PostingSchedule::class);
        $slot = $schedule->nextSlot($campaign->posts_per_day, (int) $campaign->used_today, now());

        if ($slot === null) {
            return null;
        }

        return $slot->isSameDay($schedule->startOfDay(now())) ? $slot->format('H:i') : 'Tomorrow '.$slot->format('H:i');
    }
}
