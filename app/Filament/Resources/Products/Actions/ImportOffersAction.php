<?php

namespace App\Filament\Resources\Products\Actions;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Exceptions\AffiliateException;
use App\Affiliates\ImportProductOffers;
use App\Enums\ProductSort;
use App\Models\AffiliateAccount;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Search an affiliate program's catalogue and add the results as products.
 */
class ImportOffersAction
{
    public static function make(): Action
    {
        return Action::make('importOffers')
            ->label('Import from catalogue')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalDescription('Searches the program\'s product catalogue. Products you already have are skipped.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                Select::make('affiliate_account_id')
                    ->label('Affiliate account')
                    ->options(fn (): array => self::searchableAccounts())
                    ->helperText('Only accounts whose program supports search and that have API credentials are listed.')
                    ->required(),
                TextInput::make('keyword')
                    ->required()
                    ->maxLength(100),
                Select::make('sort')
                    ->options(ProductSort::class)
                    ->default(ProductSort::Relevance)
                    ->required(),
                TextInput::make('limit')
                    ->label('How many')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default(20)
                    ->required(),
            ])
            ->action(function (array $data, Action $action, AffiliateProviderFactory $providers, ImportProductOffers $import): void {
                $account = AffiliateAccount::findOrFail($data['affiliate_account_id']);
                $sort = $data['sort'] instanceof ProductSort ? $data['sort'] : ProductSort::from($data['sort']);

                try {
                    $offers = $providers->make($account)->searchProducts($data['keyword'], (int) $data['limit'], $sort);
                } catch (AffiliateException $e) {
                    Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $result = $import->handle($account, $offers);

                Notification::make()
                    ->title("Imported {$result['created']} ".str('product')->plural($result['created']))
                    ->body($result['skipped'] > 0 ? "{$result['skipped']} were already in your list." : null)
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private static function searchableAccounts(): array
    {
        $providers = app(AffiliateProviderFactory::class);

        return AffiliateAccount::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (AffiliateAccount $account): bool => AffiliateProviderFactory::supports($account->program)
                && $providers->make($account)->canSearchProducts())
            ->pluck('name', 'id')
            ->all();
    }
}
