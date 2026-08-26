<?php

namespace App\Filament\Resources\ClassifiedCategoryResource\Pages;

use App\Filament\Resources\ClassifiedCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateClassifiedCategory extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = ClassifiedCategoryResource::class;
}
