<?php

namespace App\Filament\Resources\ScraperSourceResource\Pages;

use App\Filament\Resources\ScraperSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateScraperSource extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = ScraperSourceResource::class;
}
