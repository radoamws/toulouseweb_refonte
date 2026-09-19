<?php

namespace App\Console\Commands\Migration;

use App\Models\Category;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Corrige les libellés bruts hérités de l'import legacy pour les
 * sous-rubriques de "Restaurants" — demande client, 19/09/2026 : "il y avait
 * une rubrique 'restaurant spectacle'", or la sous-catégorie migrée
 * s'appelle littéralement `diner_spectacle` (valeur technique legacy
 * `site_client.cat`, jamais retraduite en libellé lisible). Voir
 * TECHNICAL_DOCUMENTATION.md — les slugs restent inchangés (SEO/URLs déjà
 * indexées), seul `name` (libellé affiché) est corrigé.
 *
 * Scopé au parent "Restaurants" par précaution (pas une correction globale
 * de tous les slugs identiques ailleurs dans l'arbre `categories`).
 */
class NormalizeRestaurantSubcategoryNames extends Command
{
    protected $signature = 'content:normalize-restaurant-subcategories {--dry-run : Affiche ce qui serait renommé sans rien modifier}';

    protected $description = "Corrige les libellés bruts legacy des sous-rubriques de Restaurants (ex. diner_spectacle -> Restaurant spectacle)";

    private const RENAMES = [
        'diner-spectacle' => 'Restaurant spectacle',
        'ambiance' => 'Ambiance',
        'gastronomie' => 'Gastronomie',
        'specialites' => 'Spécialités',
        'traditionnel' => 'Traditionnel',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('normalize-restaurant-subcategories');

        $parent = Category::where('slug', 'restaurants')->first();
        if (! $parent) {
            $this->error("Catégorie \"restaurants\" introuvable — rien à corriger.");

            return self::FAILURE;
        }

        $updated = 0;

        foreach (self::RENAMES as $slug => $newName) {
            $category = Category::where('parent_id', $parent->id)->where('slug', $slug)->first();

            if (! $category) {
                $log->skipped("Sous-catégorie slug={$slug} introuvable sous Restaurants.");

                continue;
            }

            if ($category->name === $newName) {
                $log->skipped("#{$category->id} \"{$category->name}\" déjà correct.");

                continue;
            }

            if ($dryRun) {
                $log->skipped("[dry-run] #{$category->id} \"{$category->name}\" -> \"{$newName}\"");

                continue;
            }

            $old = $category->name;
            $category->update(['name' => $newName]);
            $updated++;
            $log->updated("#{$category->id} \"{$old}\" -> \"{$newName}\"");
        }

        $this->info($dryRun ? 'Dry-run terminé — voir storage/logs/migration/normalize-restaurant-subcategories.log.' : "{$updated} sous-catégorie(s) renommée(s).");
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
