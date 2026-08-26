<?php

namespace App\Filament\Resources\PartnerSiteResource\Pages;

use App\Filament\Resources\PartnerSiteResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

use App\Filament\Concerns\RedirectsToIndexAfterSave;

class CreatePartnerSite extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = PartnerSiteResource::class;
}
