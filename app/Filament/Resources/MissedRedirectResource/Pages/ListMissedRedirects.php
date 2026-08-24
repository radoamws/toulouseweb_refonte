<?php

namespace App\Filament\Resources\MissedRedirectResource\Pages;

use App\Filament\Resources\MissedRedirectResource;
use Filament\Resources\Pages\ListRecords;

/** Pas d'action "Créer" : cette liste est alimentée automatiquement, jamais saisie à la main. */
class ListMissedRedirects extends ListRecords
{
    protected static string $resource = MissedRedirectResource::class;
}
