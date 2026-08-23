<?php

namespace App\Filament\Resources\ClassifiedResource\Pages;

use App\Filament\Resources\ClassifiedResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClassifieds extends ListRecords
{
    protected static string $resource = ClassifiedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
