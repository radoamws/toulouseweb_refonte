<?php

namespace App\Console\Commands\Migration;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Petites annonces (t_annonce -> classifieds), commande manquante jusqu'ici
 * (trouvée à l'audit de cutover prod du 06/09/2026, TECHNICAL_DOCUMENTATION.md
 * §22) — `migrate:reference-data` migre déjà `t_annonce_category`
 * (catégories) mais aucune commande ne migrait le contenu (`t_annonce`).
 * Volume réel très faible : seulement 3 lignes en base, datées de 2020.
 *
 * `t_annonce` n'a AUCUNE colonne de statut/modération — toutes les annonces
 * y étaient de fait "publiées" sans validation. La refonte impose une
 * modération stricte pour ce module (brief §8 : "jamais de publication
 * automatique") — appliquée ici aussi aux annonces MIGRÉES, pas seulement
 * aux nouvelles : statut `pending`, à valider manuellement par l'admin
 * comme n'importe quelle nouvelle annonce. Ce choix a aussi le mérite de ne
 * jamais publier automatiquement une des 3 lignes réelles trouvées, qui est
 * un texte manifestement frauduleux ("offre de prêt entre particuliers") —
 * l'admin la rejettera au lieu qu'elle apparaisse en ligne de fait.
 *
 * `t_annonce_categ` (pivot legacy) autorise plusieurs catégories par
 * annonce, mais `classifieds.category_id` est un simple `belongsTo` (une
 * seule catégorie) — on prend la première catégorie résolue, comme c'est
 * déjà le choix assumé ailleurs pour ce genre d'écart de cardinalité.
 *
 * Photo (`t_annonce.image`) : migrée par `images:classifieds` (galerie
 * MediaLibrary `photos`), séparément — voir ImportClassifiedPhotos.
 */
class MigrateClassifieds extends Command
{
    protected $signature = 'migrate:classifieds';

    protected $description = 'Migre les petites annonces (t_annonce) vers classifieds, en statut pending (modération manuelle requise)';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $log = new MigrationLog('classifieds');

        $categoryMap = ClassifiedCategory::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $adCategories = DB::connection('legacy')->table('t_annonce_categ')->get()->groupBy('id_annonce');

        Classified::withoutEvents(function () use ($log, $categoryMap, $adCategories) {
            DB::connection('legacy')->table('t_annonce')->orderBy('id')->chunk(200, function ($rows) use ($log, $categoryMap, $adCategories) {
                foreach ($rows as $row) {
                    $title = LegacyCleaner::text($row->titre);
                    $description = LegacyCleaner::text($row->description);

                    if (! $title || ! $description) {
                        $log->skipped("Annonce legacy #{$row->id} sans titre/description — ignorée.");

                        continue;
                    }

                    $existing = Classified::withTrashed()->where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug(null, $title, 'classifieds', $existing?->id);

                    $categoryIds = ($adCategories[$row->id] ?? collect())
                        ->map(fn ($r) => $categoryMap[$r->id_category] ?? null)->filter()->values();
                    $categoryId = $categoryIds->first();

                    // `classifieds.category_id` n'est PAS nullable (contrainte du
                    // formulaire public, toujours renseigné) — sans catégorie
                    // résolue, impossible de créer la ligne, on l'ignore plutôt
                    // que de planter.
                    if (! $categoryId) {
                        $log->skipped("Annonce legacy #{$row->id} ({$title}) : aucune catégorie legacy résolue, ignorée (category_id non nullable).");

                        continue;
                    }

                    $classified = Classified::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'category_id' => $categoryId,
                            'title' => $title,
                            'slug' => $slug,
                            'description' => $description,
                            'price' => is_numeric($row->prix) ? (string) $row->prix : null,
                            'contact_phone' => LegacyCleaner::text($row->tel),
                            'contact_email' => LegacyCleaner::text($row->mail),
                            // Jamais publiée automatiquement, même pour du contenu migré — voir docblock de classe.
                            'status' => 'pending',
                        ]
                    );

                    if (! $existing) {
                        // created_at fixé après coup pour préserver la date réelle de
                        // l'annonce (Eloquent écrase created_at à la création malgré
                        // la valeur fournie) — même piège déjà documenté dans
                        // MigrateContacts.
                        $classified->timestamps = false;
                        $classified->created_at = LegacyCleaner::date($row->date_add) ?? now();
                        $classified->save();
                    }

                    $existing ? $log->updated("#{$row->id} -> #{$classified->id} ({$title})") : $log->created("#{$row->id} -> #{$classified->id} ({$title})");
                }
            });
        });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
