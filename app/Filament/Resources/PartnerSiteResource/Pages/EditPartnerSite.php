<?php

namespace App\Filament\Resources\PartnerSiteResource\Pages;

use App\Filament\Resources\PartnerSiteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPartnerSite extends EditRecord
{
    protected static string $resource = PartnerSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
