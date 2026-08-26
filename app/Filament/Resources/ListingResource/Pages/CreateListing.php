<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Resources\ListingResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreateListing extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = ListingResource::class;
}
