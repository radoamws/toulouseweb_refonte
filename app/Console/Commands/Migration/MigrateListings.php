<?php

namespace App\Console\Commands\Migration;

use App\Models\Amenity;
use App\Models\Category;
use App\Models\Listing;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 2 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : fiches
 * annuaire (t_article -> listings), associations catégories (t_art_categ) et
 * équipements/pictos (t_encadre_icone). Nécessite `migrate:reference-data`
 * exécutée avant (catégories, équipements).
 *
 * Hypothèse de mapping de statut (à valider avec le client, voir log) :
 * t_article.statut 0 -> archived, 1 -> published, 2 -> pending.
 *
 * Les images (t_carousel) ne sont PAS transférées ici : seuls les fichiers
 * binaires manquent (non présents dans ce dépôt) — voir brief §16, à traiter
 * séparément (rsync/re-upload depuis la prod). Le nombre d'images legacy par
 * fiche est journalisé pour ne rien perdre de vue.
 */
class MigrateListings extends Command
{
    protected $signature = 'migrate:listings';

    protected $description = 'Migre les fiches annuaire (t_article) vers listings, avec catégories et équipements';

    protected const STATUS_MAP = [0 => 'archived', 1 => 'published', 2 => 'pending'];

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $log = new MigrationLog('listings');

        // Catégories/équipements legacy_id -> id (chargés une fois, tables petites)
        $categoryMap = Category::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $amenityMap = Amenity::whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        $articleCategories = DB::connection('legacy')->table('t_art_categ')->get()->groupBy('id_article');
        $articleAmenities = DB::connection('legacy')->table('t_encadre_icone')->get()->groupBy('id_encadre');
        $carouselCounts = DB::connection('legacy')->table('t_carousel')->select('id_article', DB::raw('COUNT(*) as n'))->groupBy('id_article')->pluck('n', 'id_article');

        Listing::withoutEvents(function () use ($log, $categoryMap, $amenityMap, $articleCategories, $articleAmenities, $carouselCounts) {
            DB::connection('legacy')->table('t_article')->orderBy('id')->chunk(200, function ($rows) use ($log, $categoryMap, $amenityMap, $articleCategories, $articleAmenities, $carouselCounts) {
                foreach ($rows as $row) {
                    $title = LegacyCleaner::text($row->titre);
                    if (! $title) {
                        $log->skipped("Article legacy #{$row->id} sans titre — ignoré.");

                        continue;
                    }

                    $existing = Listing::withTrashed()->where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($row->slug ?: $row->slug_old, $title, 'listings', $existing?->id);

                    // Recherche géographique (brief §5) : la base legacy n'a
                    // jamais stocké de lat/lng structurées, seulement `adresse`
                    // en texte libre — extraction best-effort du code postal
                    // + ville (voir LegacyCleaner::postalAndCity) pour au
                    // moins permettre un filtre par ville. Coordonnées
                    // lat/lng réelles hors scope (nécessiterait un service de
                    // géocodage externe, voir TECHNICAL_DOCUMENTATION.md §13).
                    [$postalCode, $city] = LegacyCleaner::postalAndCity($row->adresse);

                    $listing = Listing::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'title' => $title,
                            'slug' => $slug,
                            'tier' => (int) $row->payant === 1 ? 'paid' : 'free',
                            'status' => self::STATUS_MAP[(int) $row->statut] ?? 'draft',
                            'short_description' => LegacyCleaner::text($row->sous_titre),
                            'description' => LegacyCleaner::text($row->description) ?? LegacyCleaner::text($row->descr_gratuit),
                            'address' => LegacyCleaner::text($row->adresse),
                            'city' => $city,
                            'postal_code' => $postalCode,
                            'phone' => LegacyCleaner::text($row->tel),
                            'email' => LegacyCleaner::text($row->email),
                            'website' => LegacyCleaner::text($row->url) ?? LegacyCleaner::text($row->lien_web),
                            'opening_hours' => ($h = LegacyCleaner::text($row->ouverture)) ? ['legacy_text' => $h] : null,
                            'reservation_url' => LegacyCleaner::text($row->lien_resto1),
                            'click_collect_url' => LegacyCleaner::text($row->lien_clickcollect),
                            'published_at' => LegacyCleaner::date($row->date_insert),
                        ]
                    );

                    $existing ? $log->updated("#{$row->id} -> #{$listing->id} ({$title})") : $log->created("#{$row->id} -> #{$listing->id} ({$title})");

                    $categoryIds = ($articleCategories[$row->id] ?? collect())
                        ->map(fn ($r) => $categoryMap[$r->id_category] ?? null)->filter()->values();
                    if ($categoryIds->isEmpty()) {
                        $log->warn("Fiche #{$listing->id} ({$title}) : aucune catégorie legacy résolue.");
                    }
                    $listing->categories()->sync($categoryIds);

                    $amenityIds = ($articleAmenities[$row->id] ?? collect())
                        ->map(fn ($r) => $amenityMap[$r->id_icone] ?? null)->filter()->values();
                    $listing->amenities()->sync($amenityIds);

                    if ($n = ($carouselCounts[$row->id] ?? 0)) {
                        $log->warn("Fiche #{$listing->id} ({$title}) : {$n} image(s) legacy en galerie non transférée(s) (fichiers absents, voir brief §16).");
                    }
                }
            });
        });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
