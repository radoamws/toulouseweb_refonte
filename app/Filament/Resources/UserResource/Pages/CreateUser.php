<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateUser extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = UserResource::class;
}
