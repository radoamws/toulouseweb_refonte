<?php

namespace App\Filament\Resources\ClassifiedResource\Pages;

use App\Filament\Resources\ClassifiedResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateClassified extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = ClassifiedResource::class;
}
