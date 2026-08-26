<?php

namespace App\Filament\Resources\NewsCategoryResource\Pages;

use App\Filament\Resources\NewsCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateNewsCategory extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = NewsCategoryResource::class;
}
