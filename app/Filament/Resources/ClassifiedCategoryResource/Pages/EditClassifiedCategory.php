<?php

namespace App\Filament\Resources\ClassifiedCategoryResource\Pages;

use App\Filament\Resources\ClassifiedCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClassifiedCategory extends EditRecord
{
    protected static string $resource = ClassifiedCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
