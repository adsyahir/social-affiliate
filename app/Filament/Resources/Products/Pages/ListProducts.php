<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Actions\ImportOffersAction;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportOffersAction::make(),
            CreateAction::make(),
        ];
    }
}
