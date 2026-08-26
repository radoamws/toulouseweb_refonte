<?php

namespace App\Filament\Resources\ContactMessageResource\Pages;

use App\Filament\Resources\ContactMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateContactMessage extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = ContactMessageResource::class;
}
