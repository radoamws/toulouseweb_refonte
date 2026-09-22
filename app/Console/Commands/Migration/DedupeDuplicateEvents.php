<?php

namespace App\Console\Commands\Migration;

use App\Models\Event;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Supprime (soft delete) les événements strictement en doublon : même
 * titre, même lieu, même jour, PEU IMPORTE la source — demande client,
 * 22/09/2026 ("il faut dédupliquer partout les agendas"). Complète
 * `content:dedupe-agenda-manual-entries` (doublon manuel VS scrapé) : ce
 * cas-ci concerne des paires SCRAPÉES DEUX FOIS avec des `external_ref`
 * DIFFÉRENTS pour ce qui est manifestement le même événement.
 *
 * Contexte trouvé en investiguant (aire "Toulouse Métropole", agrégateur
 * OpenAgenda) : un même événement y est parfois posté plusieurs fois par
 * l'organisateur sous des slugs différents, ex.
 * "cafe-lire-le-petit-cercle-litteraire" ET
 * "cafe-lire-le-petit-cercle-litteraire-2972161" — une donnée dupliquée EN
 * AMONT (sur OpenAgenda lui-même), fidèlement reproduite ici (chaque
 * `external_ref` = 1 ligne, par conception de `updateOrCreate`). 16 groupes
 * / 18 lignes en trop trouvés en production le 22/09/2026.
 *
 * Regroupe par (titre, lieu, jour). Dans chaque groupe de 2+ lignes,
 * conserve UNE seule ligne, triée par :
 *   1. `external_ref` renseigné avant NULL (préfère une ligne scrapée).
 *   2. `external_ref` le plus COURT (heuristique : le slug "canonique" sans
 *      suffixe numérique parasite est généralement le plus court).
 *   3. À égalité, l'id le plus bas (la plus ancienne).
 *
 * ⚠️ Élargi (22/09/2026, vérifié en production après le premier passage) :
 * le titre est comparé APRÈS normalisation (minuscules + espaces Unicode
 * variés réduits à une espace simple), PAS par égalité stricte — trouvé en
 * production même un "même titre" scrapé deux fois par OpenAgenda peut en
 * réalité différer par la casse ("Les Voyages..." / "Les voyages...") ou
 * par le type d'espace utilisé (espace normale vs espace fine insécable
 * U+202F avant un "?", une convention typographique française appliquée de
 * façon incohérente d'un poste à l'autre côté OpenAgenda). Le TITRE STOCKÉ
 * n'est jamais modifié, seule la clé de regroupement l'est.
 */
class DedupeDuplicateEvents extends Command
{
    protected $signature = 'content:dedupe-duplicate-events {--dry-run : Affiche ce qui serait supprimé sans rien modifier}';

    protected $description = "Supprime les événements strictement en doublon (même titre/lieu/jour), quelle que soit la source";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('agenda-duplicate-events');

        $groups = Event::query()
            ->where('status', 'published')
            ->whereNotNull('area_id')
            ->whereNotNull('start_date')
            ->get()
            ->groupBy(fn (Event $event) => $event->area_id.'|'.$this->normalizeTitle($event->title).'|'.$event->start_date->toDateString())
            ->filter(fn ($group) => $group->count() > 1);

        $deleted = 0;

        // withoutEvents() : suppression en lot, même garde-fou que
        // content:dedupe-agenda-manual-entries / content:merge-duplicate-area
        // (ne jamais déclencher CloudflarePurgeObserver/GoogleIndexingObserver
        // /RegeneratesSitemapObserver par ligne).
        Event::withoutEvents(function () use ($groups, $dryRun, $log, &$deleted) {
            foreach ($groups as $group) {
                $sorted = $group->sortBy(fn (Event $e) => [
                    $e->external_ref === null ? 1 : 0,
                    $e->external_ref !== null ? strlen($e->external_ref) : 0,
                    $e->id,
                ])->values();

                $keep = $sorted->first();

                foreach ($sorted->slice(1) as $duplicate) {
                    if ($dryRun) {
                        $log->skipped("[dry-run] #{$duplicate->id} \"{$duplicate->title}\" ({$duplicate->start_date->toDateString()}) — doublon de #{$keep->id}, serait supprimé.");

                        continue;
                    }

                    $duplicate->delete();
                    $deleted++;
                    $log->created("#{$duplicate->id} \"{$duplicate->title}\" ({$duplicate->start_date->toDateString()}) supprimé — doublon de #{$keep->id}.");
                }
            }
        });

        if ($dryRun) {
            $this->info('Dry-run terminé — voir storage/logs/migration/agenda-duplicate-events.log pour le détail.');
        } else {
            $this->info("{$deleted} événement(s) en doublon supprimé(s) (soft delete, récupérables via l'admin si besoin).");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }

    /**
     * Minuscules + toutes les espaces Unicode (espace normale, insécable,
     * fine insécable U+202F...) réduites à une espace simple — voir
     * docblock de classe. Comparaison uniquement, ne modifie jamais le
     * titre stocké.
     */
    protected function normalizeTitle(string $title): string
    {
        $normalized = mb_strtolower(trim($title));
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
