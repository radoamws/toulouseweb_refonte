<?php

namespace App\Filament\Resources\ScraperSourceResource\Pages;

use App\Filament\Resources\ScraperSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListScraperSources extends ListRecords
{
    protected static string $resource = ScraperSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
