<?php

namespace App\Filament\Resources\AffiliateAccounts\Pages;

use App\Filament\Resources\AffiliateAccounts\AffiliateAccountResource;
use App\Filament\Resources\AffiliateAccounts\Schemas\AffiliateAccountForm;
use App\Models\AffiliateAccount;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAffiliateAccount extends EditRecord
{
    protected static string $resource = AffiliateAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Products and campaigns hold a foreign key to the account; remove those first.
            DeleteAction::make()
                ->hidden(fn (AffiliateAccount $record): bool => $record->products()->exists() || $record->campaigns()->exists()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AffiliateAccountForm::withCredentials($data);
    }

    /**
     * Clear the entered secrets from the form once they are stored.
     */
    protected function afterSave(): void
    {
        $this->fillForm();
    }
}
