<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateCategory extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = CategoryResource::class;
}
