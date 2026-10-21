<?php

namespace App\Filament\Resources\AffiliateAccounts\Pages;

use App\Filament\Resources\AffiliateAccounts\AffiliateAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAffiliateAccounts extends ListRecords
{
    protected static string $resource = AffiliateAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
