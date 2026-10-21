<?php

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\Actions\ConnectionActions;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Models\SocialAccount;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSocialAccount extends EditRecord
{
    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConnectionActions::make(),
            // Posts keep a foreign key to the account so their history and insights survive.
            DeleteAction::make()
                ->hidden(fn (SocialAccount $record): bool => $record->posts()->exists()),
        ];
    }

    /**
     * Clear a newly entered token from the form once it is stored. A new token can change which
     * Connection buttons apply, and header buttons are only worked out on page load, so reload.
     */
    protected function afterSave(): void
    {
        $this->fillForm();

        if ($this->record->wasChanged('access_token')) {
            $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
        }
    }
}
