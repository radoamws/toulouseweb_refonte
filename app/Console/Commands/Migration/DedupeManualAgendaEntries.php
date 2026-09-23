<?php

namespace App\Console\Commands\Migration;

use App\Models\Event;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Supprime (soft delete) les événements agenda "manuels" qui font doublon
 * avec une entrée scrapée existante — demande client, 19/09/2026 ("il y a
 * des doublons [...] à corriger", voir TECHNICAL_DOCUMENTATION.md §47).
 *
 * Contexte trouvé en investiguant : avant la mise en place des scrapers
 * agenda (§13), plusieurs lieux avaient déjà des événements saisis
 * manuellement (`source = 'manual'`, `external_ref = NULL`) — souvent avec
 * seulement une date (heure à minuit) sans horaire précis. Depuis, le
 * scraper de CE MÊME lieu a repris le MÊME événement (même titre, même
 * jour), cette fois avec un `external_ref` et un horaire réel — les deux
 * lignes coexistent et s'affichent toutes les deux côté public, ce qui
 * ressemble à un doublon (confirmé : 125 cas en production le 19/09/2026,
 * ex. "Le Bijou Comédie Club" en double le 13/10/2026 : une ligne manuelle
 * à minuit, une ligne scrapée à 19h).
 *
 * Critère volontairement CONSERVATEUR pour ne jamais supprimer un
 * événement manuel légitime (créé à la main dans l'admin pour un lieu SANS
 * scraper) : une ligne `external_ref IS NULL` n'est supprimée QUE s'il
 * existe une AUTRE ligne (même lieu, même JOUR — pas la même heure,
 * volontairement, vu l'écart minuit/heure réelle ci-dessus) avec un
 * `external_ref` renseigné. Sans ce jumeau scrapé, la ligne manuelle est
 * laissée intacte, quelle que soit sa date.
 *
 * ⚠️ Élargi (22/09/2026, capture client — nouveaux doublons trouvés type
 * "Camera Obscura" / "Camera Obscura - (Hors-les-murs)") : le titre scrapé
 * PAR L'ESCALE (plateforme Ardei-Soft/VEL) ajoute parfois un suffixe au nom
 * de base ("- (Hors-les-murs)", un même intitulé générique tronqué...) —
 * une correspondance EXACTE de titre ratait donc ces paires. Le titre
 * manuel doit désormais seulement être un PRÉFIXE du titre scrapé (même
 * lieu/jour) — sûr en pratique : deux événements RÉELLEMENT différents
 * dans le MÊME lieu le MÊME jour, dont l'un est un préfixe exact de
 * l'autre, n'a jamais été observé, contrairement au cas générique
 * "même titre".
 *
 * ⚠️ 2e critère ajouté (23/09/2026, capture client — encore des doublons
 * sur L'Escale, ex. "Un petit parad(i)s (studio)" / "Un petit parad(i)s -
 * (Hors-les-murs)") : dans BEAUCOUP de cas sur L'Escale, le titre scrapé a
 * ENTIÈREMENT changé de formulation (pas juste un suffixe ajouté) et le
 * critère par préfixe ne matche plus du tout — MAIS `external_ref`
 * (`$spectacle['s']` côté VEL, voir AbstractArdeiSoftDriver) correspond
 * alors EXACTEMENT à l'ancien titre manuel tel quel (ex. manuel
 * "Atelier musical + parad(i)s" / scrapé external_ref="Atelier musical +
 * parad(i)s", titre scrapé pourtant devenu "Atelier musical en lien avec
 * "Un petit parad(i)s""). `external_ref` étant UNIQUE, cette correspondance
 * ne peut désigner qu'UNE seule ligne scrapée à la fois — pas besoin de
 * contrainte de jour ici, contrairement au critère par préfixe. 19 cas
 * supplémentaires trouvés en production avec ce seul critère.
 */
class DedupeManualAgendaEntries extends Command
{
    protected $signature = 'content:dedupe-agenda-manual-entries {--dry-run : Affiche ce qui serait supprimé sans rien modifier}';

    protected $description = "Supprime les événements manuels qui font doublon avec une entrée déjà scrapée (même titre/lieu/jour)";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('agenda-manual-dupes');

        $candidates = Event::query()
            ->whereNull('external_ref')
            ->where('source', 'manual')
            ->get();

        $deleted = 0;

        // withoutEvents() : suppression en lot, ne doit jamais déclencher
        // CloudflarePurgeObserver/GoogleIndexingObserver/RegeneratesSitemapObserver
        // par ligne (pattern déjà établi dans ce projet pour toute écriture
        // en masse, voir TECHNICAL_DOCUMENTATION.md — épuiserait le quota
        // Google Indexing, 200 requêtes/jour, pour une simple purge de doublons).
        Event::withoutEvents(function () use ($candidates, $dryRun, $log, &$deleted) {
            foreach ($candidates as $manual) {
                if (! $manual->start_date) {
                    continue;
                }

                // LIKE "{titre}%" — le titre manuel doit être un PRÉFIXE du
                // titre scrapé (voir docblock de classe) ; on échappe les
                // métacaractères LIKE (%, _) au cas où le titre manuel en
                // contienne littéralement.
                $titlePrefix = addcslashes($manual->title, '%_');

                $hasScrapedTwin = Event::query()
                    ->whereNotNull('external_ref')
                    ->where('area_id', $manual->area_id)
                    ->where('id', '!=', $manual->id)
                    ->where(function ($q) use ($titlePrefix, $manual) {
                        // Critère 1 : préfixe de titre, même jour (voir
                        // docblock). Critère 2 : external_ref == ancien
                        // titre manuel, sans contrainte de jour (unique,
                        // ne peut désigner qu'une ligne).
                        $q->where(fn ($q2) => $q2->where('title', 'like', "{$titlePrefix}%")
                            ->whereDate('start_date', $manual->start_date->toDateString()))
                            ->orWhere('external_ref', $manual->title);
                    })
                    ->exists();

                if (! $hasScrapedTwin) {
                    continue;
                }

                if ($dryRun) {
                    $log->skipped("[dry-run] #{$manual->id} \"{$manual->title}\" ({$manual->start_date->toDateString()}) — doublon d'une entrée scrapée, serait supprimé.");

                    continue;
                }

                $manual->delete();
                $deleted++;
                $log->created("#{$manual->id} \"{$manual->title}\" ({$manual->start_date->toDateString()}) supprimé — doublon d'une entrée scrapée.");
            }
        });

        if ($dryRun) {
            $this->info('Dry-run terminé — voir storage/logs/migration/agenda-manual-dupes.log pour le détail.');
        } else {
            $this->info("{$deleted} événement(s) en doublon supprimé(s) (soft delete, récupérables via l'admin si besoin).");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
