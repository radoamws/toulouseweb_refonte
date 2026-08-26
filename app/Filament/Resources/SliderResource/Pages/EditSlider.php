<?php

namespace App\Filament\Resources\SliderResource\Pages;

use App\Filament\Resources\SliderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class EditSlider extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = SliderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
