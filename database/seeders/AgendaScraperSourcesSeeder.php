<?php

namespace Database\Seeders;

use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\TheatreDeLaCiteDriver;
use Illuminate\Database\Seeder;

/**
 * Sources de scraping agenda (brief §6/§21). À exécuter une fois par
 * environnement : `php artisan db:seed --class=AgendaScraperSourcesSeeder`.
 * Voir TECHNICAL_DOCUMENTATION.md §13 pour le contexte (aucun scraper agenda
 * legacy fonctionnel retrouvé — reconstruit depuis zéro contre les vrais
 * sites identifiés dans les données réelles).
 */
class AgendaScraperSourcesSeeder extends Seeder
{
    public function run(): void
    {
        ScraperSource::updateOrCreate(
            ['name' => 'Théâtre de la Cité'],
            [
                'type' => 'agenda',
                'driver_class' => TheatreDeLaCiteDriver::class,
                'config' => [
                    'listing_url' => 'https://theatre-cite.com/programmation',
                    'area_slug' => 'tnt-theatre-de-la-cite',
                    'event_category_slug' => 'theatre',
                ],
                'is_active' => true,
            ]
        );
    }
}
