<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditUser extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Un compte ne peut pas se supprimer lui-même (voir UserResource::table()).
            Actions\DeleteAction::make()->visible(fn () => $this->record->id !== auth()->id()),
        ];
    }
}
