<?php

namespace App\Filament\Resources\MovieResource\Pages;

use App\Filament\Resources\MovieResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditMovie extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = MovieResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
