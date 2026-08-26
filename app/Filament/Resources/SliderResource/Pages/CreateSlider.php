<?php

namespace App\Filament\Resources\SliderResource\Pages;

use App\Filament\Resources\SliderResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateSlider extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = SliderResource::class;
}
