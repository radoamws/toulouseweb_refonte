<?php

namespace App\Filament\Resources\NewsCategoryResource\Pages;

use App\Filament\Resources\NewsCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditNewsCategory extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = NewsCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
