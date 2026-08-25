<?php

namespace Database\Seeders;

use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\ArdeiDriver;
use App\Services\Scraping\Agenda\BijouDriver;
use App\Services\Scraping\Agenda\CasinoBarriereDriver;
use App\Services\Scraping\Agenda\EscaleDriver;
use App\Services\Scraping\Agenda\GaronneDriver;
use App\Services\Scraping\Agenda\GrandRondDriver;
use App\Services\Scraping\Agenda\InterpreteDriver;
use App\Services\Scraping\Agenda\LeventDesSignesDriver;
use App\Services\Scraping\Agenda\MetropoleDriver;
use App\Services\Scraping\Agenda\OdyssudDriver;
use App\Services\Scraping\Agenda\TheatreDeLaCiteDriver;
use App\Services\Scraping\Agenda\ZenithDriver;
use Illuminate\Database\Seeder;

/**
 * Sources de scraping agenda (brief §6/§21). À exécuter une fois par
 * environnement : `php artisan db:seed --class=AgendaScraperSourcesSeeder`.
 *
 * Les 12 sources ci-dessous reproduisent EXACTEMENT la liste des tâches cron
 * de production fournie par le client (`updateAgendafor{Salle}` sur
 * `toulouseweb.com/backend/public/api/`), chacune reconstruite à partir du
 * VRAI code legacy que le client a ajouté à
 * old/backEnd/app/Http/Controllers/AgendaController.php — voir
 * TECHNICAL_DOCUMENTATION.md §13 pour le détail de l'investigation et des
 * limites connues de chaque driver.
 */
class AgendaScraperSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            'Théâtre de la Cité' => [
                'driver' => TheatreDeLaCiteDriver::class,
                'config' => [
                    'listing_url' => 'https://theatre-cite.com/programmation',
                    'area_slug' => 'tnt-theatre-de-la-cite',
                    'event_category_slug' => 'theatre',
                ],
            ],
            'Zénith Toulouse Métropole' => [
                'driver' => ZenithDriver::class,
                'config' => [
                    'openagenda_slug' => 'zenith-toulouse-metropole',
                    'area_slug' => 'le-zenith',
                ],
            ],
            'Toulouse Métropole (agenda mutualisé)' => [
                'driver' => MetropoleDriver::class,
                'config' => [
                    'openagenda_slug' => 'toulouse-metropole',
                    'area_slug' => 'toulouse-metropole',
                ],
            ],
            'Casino Théâtre Barrière' => [
                'driver' => CasinoBarriereDriver::class,
                'config' => [
                    'listing_url' => 'https://www.casinosbarriere.com/nos-spectacles',
                    'area_slug' => 'casino-theatre-barriere',
                ],
            ],
            'Théâtre Garonne' => [
                'driver' => GaronneDriver::class,
                'config' => [
                    'listing_url' => 'https://www.theatregaronne.com/saison',
                    'area_slug' => 'theatre-garonne',
                    'fallback_category_slug' => 'theatre',
                ],
            ],
            'Le Vent des Signes' => [
                'driver' => LeventDesSignesDriver::class,
                'config' => [
                    'listing_url' => 'https://www.leventdessignes.fr',
                    'area_slug' => 'le-vent-des-signes',
                    'event_category_slug' => 'theatre',
                ],
            ],
            'Odyssud Blagnac' => [
                'driver' => OdyssudDriver::class,
                'config' => [
                    'listing_url' => 'https://www.odyssud.com/spectacles/normal',
                    'area_slug' => 'odyssud-blagnac',
                ],
            ],
            "L'Escale (Tournefeuille)" => [
                'driver' => EscaleDriver::class,
                'config' => [
                    'town_slug' => 'tournefeuille',
                    'area_slug' => 'lescale-2',
                    'tarifs_group' => 3,
                ],
            ],
            'Théâtre du Grand Rond' => [
                'driver' => GrandRondDriver::class,
                'config' => [
                    'listing_url' => 'https://www.grand-rond.org/programmation',
                    'area_slug' => 'theatre-du-grand-rond-3',
                    'event_category_slug' => 'theatre',
                ],
            ],
            'Les Grands Interprètes' => [
                'driver' => InterpreteDriver::class,
                'config' => [
                    // Site refondu (nouveau CMS) depuis l'écriture du legacy —
                    // driver actuellement non fonctionnel (found=0), voir
                    // docblock de InterpreteDriver et TECHNICAL_DOCUMENTATION.md §13.
                    'listing_url' => 'https://www.grandsinterpretes.fr/saison2026-2027/',
                    'area_slug' => 'les-grands-interpretes',
                    'event_category_slug' => 'concerts',
                ],
            ],
            'Le Bijou' => [
                'driver' => BijouDriver::class,
                'config' => [
                    'api_url' => 'https://le-bijou.soticket.net/api/v2/shows?offset=0&limit=100&next=1',
                    'area_slug' => 'le-bijou',
                ],
            ],
            'Aria (Cornebarrieu)' => [
                'driver' => ArdeiDriver::class,
                'config' => [
                    'town_slug' => 'cornebarrieu',
                    'area_slug' => 'aria',
                    'tarifs_group' => 1,
                ],
            ],
        ];

        foreach ($sources as $name => $definition) {
            ScraperSource::updateOrCreate(
                ['name' => $name],
                [
                    'type' => 'agenda',
                    'driver_class' => $definition['driver'],
                    'config' => $definition['config'],
                    'is_active' => true,
                ]
            );
        }
    }
}
