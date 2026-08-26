<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateEvent extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = EventResource::class;
}
