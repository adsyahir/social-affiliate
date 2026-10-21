<?php

namespace App\Filament\Resources\SocialAccounts;

use App\Filament\Resources\SocialAccounts\Pages\CreateSocialAccount;
use App\Filament\Resources\SocialAccounts\Pages\EditSocialAccount;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\SocialAccounts\Schemas\SocialAccountForm;
use App\Filament\Resources\SocialAccounts\Tables\SocialAccountsTable;
use App\Models\SocialAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SocialAccountResource extends Resource
{
    protected static ?string $model = SocialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SocialAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SocialAccountsTable::configure($table);
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
            'index' => ListSocialAccounts::route('/'),
            'create' => CreateSocialAccount::route('/create'),
            'edit' => EditSocialAccount::route('/{record}/edit'),
        ];
    }
}
