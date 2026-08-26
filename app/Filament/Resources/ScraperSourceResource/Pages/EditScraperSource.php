<?php

namespace App\Filament\Resources\ScraperSourceResource\Pages;

use App\Filament\Resources\ScraperSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditScraperSource extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = ScraperSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
