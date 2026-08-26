<?php

namespace App\Filament\Resources\EventCategoryResource\Pages;

use App\Filament\Resources\EventCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateEventCategory extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = EventCategoryResource::class;
}
