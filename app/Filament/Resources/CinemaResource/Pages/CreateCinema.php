<?php

namespace App\Filament\Resources\CinemaResource\Pages;

use App\Filament\Resources\CinemaResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateCinema extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = CinemaResource::class;
}
