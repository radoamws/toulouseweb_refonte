<?php

namespace App\Filament\Resources\AmenityResource\Pages;

use App\Filament\Resources\AmenityResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditAmenity extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = AmenityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
