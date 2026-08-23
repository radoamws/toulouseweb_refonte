<?php

namespace App\Filament\Resources\ClassifiedResource\Pages;

use App\Filament\Resources\ClassifiedResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClassified extends EditRecord
{
    protected static string $resource = ClassifiedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
