<?php

namespace App\Filament\Resources\TransportLineResource\Pages;

use App\Filament\Resources\TransportLineResource;
use Filament\Resources\Pages\ListRecords;

/** Pas d'action "Créer" : alimentée automatiquement par transport:import-tisseo-gtfs, jamais saisie à la main. */
class ListTransportLines extends ListRecords
{
    protected static string $resource = TransportLineResource::class;
}
