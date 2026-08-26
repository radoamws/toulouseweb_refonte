<?php

namespace App\Filament\Resources\AmenityResource\Pages;

use App\Filament\Resources\AmenityResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateAmenity extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = AmenityResource::class;
}
