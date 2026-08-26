<?php

namespace App\Filament\Resources\MovieResource\Pages;

use App\Filament\Resources\MovieResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateMovie extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = MovieResource::class;
}
