<?php

namespace App\Filament\Resources\AffiliateAccounts\Schemas;

use App\Affiliates\AffiliateProviderFactory;
use App\Enums\AffiliateProgram;
use App\Models\AffiliateAccount;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AffiliateAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('program')
                    ->options(AffiliateProgram::class)
                    ->live()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('tracking_id')
                    ->label('Tracking ID')
                    ->helperText('Amazon store tag, Shopee affiliate ID, etc.')
                    ->maxLength(255),
                // Not a model attribute: stored secrets are never sent back to the browser, and
                // withCredentials() turns these rows into `credentials` only when some were entered.
                // (A KeyValue field was dropped here: its $entangle binding can lose edits on a quick save.)
                Repeater::make('credential_rows')
                    ->label('Credentials')
                    ->schema([
                        TextInput::make('key')
                            ->label('Name')
                            ->required(),
                        TextInput::make('value')
                            ->label('Secret value')
                            ->password()
                            ->revealable()
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add credential')
                    ->helperText(fn (?AffiliateAccount $record, Get $get): string => implode(' ', array_filter([
                        self::expectedCredentials($get('program')),
                        filled($record?->credentials)
                            ? 'Stored: '.implode(', ', array_keys($record->credentials)).'. Leave empty to keep them; anything you enter replaces all of them.'
                            : null,
                    ])))
                    ->columnSpanFull(),
            ]);
    }

    private static function expectedCredentials(AffiliateProgram|string|null $program): string
    {
        $program = is_string($program) ? AffiliateProgram::tryFrom($program) : $program;

        if ($program === null) {
            return 'Pick a program to see which credentials it uses.';
        }

        if (! AffiliateProviderFactory::supports($program)) {
            return "{$program->getLabel()} has no driver yet; paste tracked links on each product.";
        }

        $fields = collect(AffiliateProviderFactory::credentialFields($program))
            ->map(fn (string $description, string $name): string => "{$name} ({$description})")
            ->join('; ');

        return "Optional. Without them, paste tracked links on each product. Names: {$fields}.";
    }

    /**
     * Turn the credential rows from the form into the `credentials` attribute.
     * With no rows the existing credentials are left untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCredentials(array $data): array
    {
        $rows = $data['credential_rows'] ?? [];
        unset($data['credential_rows']);

        if (filled($rows)) {
            $data['credentials'] = collect($rows)->pluck('value', 'key')->all();
        }

        return $data;
    }
}
