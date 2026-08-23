<?php

namespace App\Console\Commands\Migration;

use App\Models\Amenity;
use App\Models\Area;
use App\Models\Category;
use App\Models\ClassifiedCategory;
use App\Models\EventCategory;
use App\Models\Language;
use App\Models\NewsCategory;
use App\Models\ScreeningType;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Étape 1 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : toutes les
 * tables de référence, dans l'ordre de dépendance. Idempotent (rejouable
 * sans dupliquer) via legacy_id/legacy_code.
 */
class MigrateReferenceData extends Command
{
    protected $signature = 'migrate:reference-data';

    protected $description = 'Migre les catégories, lieux, équipements, catégories agenda/annonces/actus et référentiels cinéma depuis toulouseweb_old';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $this->migrateCategories();
        $this->migrateAreas();
        $this->migrateAmenities();
        $this->migrateEventCategories();
        $this->migrateLanguages();
        $this->migrateScreeningTypes();
        $this->migrateClassifiedCategories();
        $this->migrateNewsCategories();

        return self::SUCCESS;
    }

    protected function migrateCategories(): void
    {
        $log = new MigrationLog('reference-data-categories');
        $legacyToNewId = [];

        Category::withoutEvents(function () use (&$legacyToNewId, $log) {
            DB::connection('legacy')->table('t_category')
                ->orderBy('niveau')->orderBy('id')
                ->get()
                ->each(function ($row) use (&$legacyToNewId, $log) {
                    $name = LegacyCleaner::text($row->nom) ?? "Catégorie #{$row->id}";
                    $parentId = $row->id_parent ? ($legacyToNewId[$row->id_parent] ?? null) : null;
                    if ($row->id_parent && $parentId === null) {
                        $log->warn("Catégorie legacy #{$row->id} ({$name}) : parent legacy #{$row->id_parent} introuvable, rattachée à la racine.");
                    }

                    $existing = Category::where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($row->slug ?: $row->slug_old, $name, 'categories', $existing?->id);

                    $category = Category::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'parent_id' => $parentId,
                            'name' => $name,
                            'slug' => $slug,
                            'level' => (int) $row->niveau,
                            'description' => LegacyCleaner::text($row->description),
                            'order' => 0,
                            'is_active' => (int) $row->statut === 1,
                        ]
                    );
                    $legacyToNewId[$row->id] = $category->id;
                    $existing ? $log->updated("#{$row->id} -> #{$category->id} ({$name})") : $log->created("#{$row->id} -> #{$category->id} ({$name})");
                });
        });

        $this->info($log->summary());
    }

    protected function migrateAreas(): void
    {
        $log = new MigrationLog('reference-data-areas');

        Area::withoutEvents(function () use ($log) {
            DB::connection('legacy')->table('t_areas')->orderBy('id')->chunk(200, function ($rows) use ($log) {
                foreach ($rows as $row) {
                    $name = LegacyCleaner::text($row->name) ?? "Lieu #{$row->id}";
                    $existing = Area::where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug(null, $name, 'areas', $existing?->id);

                    $area = Area::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'name' => $name,
                            'slug' => $slug,
                            'address' => LegacyCleaner::text($row->address),
                            'phone' => LegacyCleaner::text($row->phone),
                            'website' => LegacyCleaner::text($row->web_site),
                        ]
                    );
                    $existing ? $log->updated("#{$row->id} -> #{$area->id} ({$name})") : $log->created("#{$row->id} -> #{$area->id} ({$name})");
                }
            });
        });

        $this->info($log->summary());
    }

    protected function migrateAmenities(): void
    {
        $log = new MigrationLog('reference-data-amenities');

        foreach (DB::connection('legacy')->table('t_icone')->orderBy('id')->get() as $row) {
            $name = LegacyCleaner::text($row->titre) ?? LegacyCleaner::text($row->legende) ?? "Icône #{$row->id}";
            $existing = Amenity::where('legacy_id', $row->id)->first();

            $amenity = Amenity::updateOrCreate(
                ['legacy_id' => $row->id],
                ['name' => $name, 'icon' => LegacyCleaner::text($row->image)]
            );
            $existing ? $log->updated("#{$row->id} -> #{$amenity->id} ({$name})") : $log->created("#{$row->id} -> #{$amenity->id} ({$name})");
        }

        $this->info($log->summary());
    }

    protected function migrateEventCategories(): void
    {
        $log = new MigrationLog('reference-data-event-categories');

        EventCategory::withoutEvents(function () use ($log) {
            foreach (DB::connection('legacy')->table('t_agenda_categories')->orderBy('id')->get() as $row) {
                $label = LegacyCleaner::text($row->libelle) ?: LegacyCleaner::text($row->name);
                $name = $label ? Str::ucfirst($label) : "Catégorie #{$row->id}";
                $existing = EventCategory::where('legacy_id', $row->id)->first();
                $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($label, $name, 'event_categories', $existing?->id);

                $category = EventCategory::updateOrCreate(
                    ['legacy_id' => $row->id],
                    [
                        'name' => $name,
                        'slug' => $slug,
                        'color' => self::normalizeColor($row->color),
                        'icon' => LegacyCleaner::text($row->icon),
                        'order' => (int) $row->display_order,
                    ]
                );
                $existing ? $log->updated("#{$row->id} -> #{$category->id} ({$name})") : $log->created("#{$row->id} -> #{$category->id} ({$name})");
            }
        });

        $this->info($log->summary());
    }

    protected function migrateLanguages(): void
    {
        $log = new MigrationLog('reference-data-languages');

        foreach (DB::connection('legacy')->table('t_cine_lang')->orderBy('id')->get() as $row) {
            $name = LegacyCleaner::text($row->langue) ?? "Langue #{$row->id}";
            $existing = Language::where('legacy_id', $row->id)->first();
            $language = Language::updateOrCreate(['legacy_id' => $row->id], ['name' => $name]);
            $existing ? $log->updated("#{$row->id} -> #{$language->id}") : $log->created("#{$row->id} -> #{$language->id}");
        }

        $this->info($log->summary());
    }

    protected function migrateScreeningTypes(): void
    {
        $log = new MigrationLog('reference-data-screening-types');

        foreach (DB::connection('legacy')->table('t_cine_type_projection')->orderBy('id')->get() as $row) {
            $name = LegacyCleaner::text($row->type) ?? "Type #{$row->id}";
            $existing = ScreeningType::where('legacy_id', $row->id)->first();
            $type = ScreeningType::updateOrCreate(['legacy_id' => $row->id], ['name' => $name]);
            $existing ? $log->updated("#{$row->id} -> #{$type->id}") : $log->created("#{$row->id} -> #{$type->id}");
        }

        $this->info($log->summary());
    }

    protected function migrateClassifiedCategories(): void
    {
        // Module quasi jamais utilisé en legacy (7 lignes) — voir
        // TECHNICAL_DOCUMENTATION.md §3.2/§10 : on migre l'arbre de
        // catégories (réutilisable), pas les annonces elles-mêmes.
        $log = new MigrationLog('reference-data-classified-categories');
        $legacyToNewId = [];

        ClassifiedCategory::withoutEvents(function () use (&$legacyToNewId, $log) {
            DB::connection('legacy')->table('t_annonce_category')
                ->orderBy('niveau')->orderBy('id')->get()
                ->each(function ($row) use (&$legacyToNewId, $log) {
                    $name = LegacyCleaner::text($row->nom) ?? "Catégorie #{$row->id}";
                    $parentId = $row->id_parent ? ($legacyToNewId[$row->id_parent] ?? null) : null;
                    $existing = ClassifiedCategory::where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug(null, $name, 'classified_categories', $existing?->id);

                    $category = ClassifiedCategory::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'parent_id' => $parentId,
                            'name' => $name,
                            'slug' => $slug,
                            'is_active' => (int) $row->statut === 1,
                        ]
                    );
                    $legacyToNewId[$row->id] = $category->id;
                    $existing ? $log->updated("#{$row->id} -> #{$category->id}") : $log->created("#{$row->id} -> #{$category->id}");
                });
        });

        $this->info($log->summary());
    }

    protected function migrateNewsCategories(): void
    {
        $log = new MigrationLog('reference-data-news-categories');

        NewsCategory::withoutEvents(function () use ($log) {
            foreach (DB::connection('legacy')->table('t_news_cat')->orderBy('ordre')->get() as $row) {
                $name = LegacyCleaner::text($row->nom) ?? "Rubrique {$row->id}";
                $existing = NewsCategory::where('legacy_code', $row->id)->first();
                $slug = $existing?->slug ?? LegacyCleaner::preserveSlug(null, $name, 'news_categories', $existing?->id);

                $category = NewsCategory::updateOrCreate(
                    ['legacy_code' => $row->id],
                    ['name' => $name, 'slug' => $slug]
                );
                $existing ? $log->updated("{$row->id} -> #{$category->id} ({$name})") : $log->created("{$row->id} -> #{$category->id} ({$name})");
            }
        });

        $this->info($log->summary());
    }

    protected static function normalizeColor(?string $color): ?string
    {
        $color = LegacyCleaner::text($color);
        if (! $color) {
            return null;
        }
        // Corrige les couleurs invalides observées en legacy (ex: "#f006")
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }
        if (str_starts_with($color, 'rgb')) {
            return $color;
        }

        return null;
    }
}
