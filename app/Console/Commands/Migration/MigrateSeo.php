<?php

namespace App\Console\Commands\Migration;

use App\Models\Cinema;
use App\Models\Category;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Page;
use App\Models\SeoMeta;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Étape 8 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : SEO
 * personnalisé par entité (t_seo_entity/t_seo_groupe -> seo_meta
 * polymorphe). Nécessite les autres migrations de domaine déjà passées
 * (categories, listings, news, event_categories, cinemas, movies).
 *
 * Mapping des groupes SEO legacy (vérifié en base, voir t_seo_groupe) :
 *   1 Menu -> Page (pages statiques/sections du menu principal)
 *   2 Catégories -> Category
 *   3 Encadré -> Listing
 *   4 News -> News
 *   5 Agenda -> EventCategory (les lignes sont par catégorie d'agenda, pas
 *     par événement individuel)
 *   7 Cinéma salle -> Cinema
 *   8 Cinéma film -> Movie
 *   10 Cinéma panorama -> Page (page agrégée unique)
 *
 * Les champs `se_h1`..`se_h6` et `ext_*` (blocs de texte longs de
 * remplissage SEO façon 2010-2015, voir audit §5) ne sont volontairement
 * PAS repris : ils relèvent du contenu éditorial daté, pas d'une métadonnée
 * SEO au sens du nouveau système (title/description/canonical/OG/JSON-LD).
 */
class MigrateSeo extends Command
{
    protected $signature = 'migrate:seo';

    protected $description = 'Migre les métadonnées SEO personnalisées (t_seo_entity) vers seo_meta';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $log = new MigrationLog('seo');

        $categoryMap = Category::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $listingMap = Listing::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $newsMap = News::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $eventCategoryMap = EventCategory::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $cinemaMap = Cinema::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $movieMap = Movie::whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        $resolvers = [
            2 => fn ($legacyId) => $this->find(Category::class, $categoryMap, $legacyId),
            3 => fn ($legacyId) => $this->find(Listing::class, $listingMap, $legacyId),
            4 => fn ($legacyId) => $this->find(News::class, $newsMap, $legacyId),
            5 => fn ($legacyId) => $this->find(EventCategory::class, $eventCategoryMap, $legacyId),
            7 => fn ($legacyId) => $this->find(Cinema::class, $cinemaMap, $legacyId),
            8 => fn ($legacyId) => $this->find(Movie::class, $movieMap, $legacyId),
        ];

        foreach (DB::connection('legacy')->table('t_seo_entity')->orderBy('id')->get() as $row) {
            $title = LegacyCleaner::text($row->se_title);
            $description = LegacyCleaner::text($row->se_descr);
            if (! $title && ! $description) {
                $log->skipped("SEO legacy #{$row->id} (groupe {$row->id_seo_groupe}) sans titre ni description — ignoré.");

                continue;
            }

            if (in_array((int) $row->id_seo_groupe, [1, 10], true)) {
                $model = $this->findOrCreatePage($row);
            } else {
                $resolver = $resolvers[(int) $row->id_seo_groupe] ?? null;
                $model = $resolver ? $resolver($row->id_entite) : null;
            }

            if (! $model) {
                $log->warn("SEO legacy #{$row->id} (groupe {$row->id_seo_groupe}, entité {$row->id_entite}) : entité cible introuvable.");

                continue;
            }

            $existing = SeoMeta::where('seoable_type', $model::class)->where('seoable_id', $model->id)->first();
            SeoMeta::updateOrCreate(
                ['seoable_type' => $model::class, 'seoable_id' => $model->id],
                ['title' => $title, 'description' => $description]
            );
            $existing ? $log->updated("#{$row->id} -> ".class_basename($model)."#{$model->id}") : $log->created("#{$row->id} -> ".class_basename($model)."#{$model->id}");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }

    protected function find(string $class, \Illuminate\Support\Collection $map, ?int $legacyId): ?Model
    {
        if (! $legacyId || ! isset($map[$legacyId])) {
            return null;
        }

        return $class::find($map[$legacyId]);
    }

    protected function findOrCreatePage(object $row): Page
    {
        $name = LegacyCleaner::text($row->se_name) ?? "page-{$row->id}";
        $slugCandidate = LegacyCleaner::text($row->se_slug) ?? \Illuminate\Support\Str::slug($name);
        $normalizedSlug = \Illuminate\Support\Str::slug($slugCandidate);

        // Réutilise une page déjà existante (ex: 'accueil' -> la Page 'home'
        // créée par ailleurs) plutôt que de créer un doublon en collision de
        // slug unique.
        if ($existing = Page::where('slug', $normalizedSlug)->first()) {
            return $existing;
        }

        $key = 'seo-menu-'.$normalizedSlug;

        return Page::firstOrCreate(
            ['key' => $key],
            ['title' => $name, 'slug' => LegacyCleaner::preserveSlug($normalizedSlug, $name, 'pages')]
        );
    }
}
