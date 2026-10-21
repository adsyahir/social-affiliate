<?php

namespace App\Filament\Resources\AffiliateAccounts\Pages;

use App\Filament\Resources\AffiliateAccounts\AffiliateAccountResource;
use App\Filament\Resources\AffiliateAccounts\Schemas\AffiliateAccountForm;
use Filament\Resources\Pages\CreateRecord;

class CreateAffiliateAccount extends CreateRecord
{
    protected static string $resource = AffiliateAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AffiliateAccountForm::withCredentials($data);
    }
}
