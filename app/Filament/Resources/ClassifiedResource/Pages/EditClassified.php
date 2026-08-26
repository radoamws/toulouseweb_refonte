<?php

namespace App\Filament\Resources\ClassifiedResource\Pages;

use App\Filament\Resources\ClassifiedResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditClassified extends EditRecord
{
    use RedirectsToIndexAfterSave;

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
