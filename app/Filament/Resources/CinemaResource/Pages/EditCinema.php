<?php

namespace App\Filament\Resources\CinemaResource\Pages;

use App\Filament\Resources\CinemaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditCinema extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = CinemaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
