<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /**
     * No "New post" button: posts come from "Generate draft" or the scheduler, so they always
     * pass through the posting rules.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
