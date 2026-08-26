<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateRole extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = RoleResource::class;
}
