<?php

namespace App\Filament\Resources\AffiliateAccounts;

use App\Filament\Resources\AffiliateAccounts\Pages\CreateAffiliateAccount;
use App\Filament\Resources\AffiliateAccounts\Pages\EditAffiliateAccount;
use App\Filament\Resources\AffiliateAccounts\Pages\ListAffiliateAccounts;
use App\Filament\Resources\AffiliateAccounts\Schemas\AffiliateAccountForm;
use App\Filament\Resources\AffiliateAccounts\Tables\AffiliateAccountsTable;
use App\Models\AffiliateAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AffiliateAccountResource extends Resource
{
    protected static ?string $model = AffiliateAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AffiliateAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AffiliateAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateAccounts::route('/'),
            'create' => CreateAffiliateAccount::route('/create'),
            'edit' => EditAffiliateAccount::route('/{record}/edit'),
        ];
    }
}
