<?php

namespace App\Filament\Resources\AreaResource\Pages;

use App\Filament\Resources\AreaResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateArea extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = AreaResource::class;
}
