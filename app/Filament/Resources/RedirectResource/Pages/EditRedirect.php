<?php

namespace App\Filament\Resources\RedirectResource\Pages;

use App\Filament\Resources\RedirectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditRedirect extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
