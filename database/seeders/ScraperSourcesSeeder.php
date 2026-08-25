<?php

namespace Database\Seeders;

use App\Models\Cinema;
use App\Models\ScraperSource;
use App\Services\Scraping\Cinema\AllocineDriver;
use Illuminate\Database\Seeder;

/**
 * Sources de scraping cinéma (brief §7/§21) — une par salle active, sur le
 * modèle réel du cron legacy (`autoUpdateCinemaAllocine/{id}` appelé une
 * fois par salle, voir AllocineDriver). Correspondance salle → identifiant
 * AlloCiné extraite une fois depuis `toulouseweb_old.t_cine.url`
 * (`salle_gen_csalle=P0057.html` → `P0057`) le 2026-08-24 : 25 des 28
 * salles ont un identifiant exploitable (les 3 restantes — UGC Toulouse,
 * Le Mermoz, Espace des Nouveautés — sont inactives dans le legacy et
 * n'ont pas d'URL AlloCiné renseignée, donc pas de source créée pour
 * elles). Vérifié en direct le 25/08/2026 : 25/25 sources exécutées avec
 * succès (397 séances trouvées), voir TECHNICAL_DOCUMENTATION.md §13. À
 * exécuter une fois par environnement :
 * `php artisan db:seed --class=ScraperSourcesSeeder`.
 */
class ScraperSourcesSeeder extends Seeder
{
    /** slug (cinemas.slug) => identifiant de salle AlloCiné. */
    private const ALLOCINE_THEATER_IDS = [
        'abc' => 'P0071',
        'cgr-blagnac' => 'P0692',
        'cinematheque' => 'P0062',
        'pathe-gaumont-wilson' => 'P0057',
        'cratere' => 'P0056',
        'gaumont-labege' => 'P0645',
        'american-cosmograph' => 'P0235',
        'l-autan' => 'P0881',
        'grand-central-colomiers' => 'P6864',
        'le-castelia' => 'P0583',
        'studio-7' => 'P7875',
        'le-ventura' => 'P2225',
        'tempo-cine' => 'P3857',
        'le-lumiere' => 'P2216',
        'le-melies' => 'P2226',
        'mjc-cine-113' => 'P2235',
        'cinerex' => 'W0334',
        'cinema-ecran-7' => 'P0918',
        'jean-marais' => 'P3837',
        'l-entract' => 'P9548',
        'veo-muret' => 'W3161',
        'utopia-tournefeuille' => 'P3404',
        'kinepolis-fenouillet' => 'W3115',
        'utopia-borderouge' => 'W3120',
        'ugc-montaudran' => 'W3140',
    ];

    public function run(): void
    {
        foreach (self::ALLOCINE_THEATER_IDS as $slug => $theaterId) {
            $cinema = Cinema::where('slug', $slug)->first();

            if (! $cinema) {
                continue;
            }

            ScraperSource::updateOrCreate(
                ['name' => "AlloCiné — {$cinema->name}"],
                [
                    'type' => 'cinema',
                    'driver_class' => AllocineDriver::class,
                    'config' => [
                        'allocine_theater_id' => $theaterId,
                        'cinema_id' => $cinema->id,
                        'window_days' => 7,
                    ],
                    'is_active' => $cinema->is_active,
                ]
            );
        }
    }
}
