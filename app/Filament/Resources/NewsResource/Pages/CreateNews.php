<?php

namespace App\Filament\Resources\NewsResource\Pages;

use App\Filament\Resources\NewsResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateNews extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = NewsResource::class;
}
