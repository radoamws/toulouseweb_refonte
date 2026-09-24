<?php

namespace App\Console\Commands\Migration;

use App\Models\Event;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Demande client, 24/09/2026 : "Supprime tous les scrapings des agendas (NE
 * TOUCHE PAS CELUI DES CINÉMAS NI LES AGENDAS SAISIS MANUELLEMENT) et relance
 * complètement le scraping car il y a beaucoup de doublons et d'incohérence
 * de date." (voir TECHNICAL_DOCUMENTATION.md §55/§56).
 *
 * Portée strictement limitée à `events.source = 'scraped'` : ne touche NI aux
 * `source = 'manual'`/`user_submitted'` (agendas saisis à la main), NI aux
 * `Movie`/`Screening`/`ScreeningTime` (scraping cinéma, table complètement
 * séparée, jamais concernée par cette commande).
 *
 * Suppression DÉFINITIVE (pas un simple soft delete) — choix explicite du
 * client face au compromis suivant : `events.slug` a une contrainte UNIQUE en
 * base, et `HasSlug` (spatie/laravel-sluggable) évite les collisions de slug
 * même avec les lignes soft-deleted (`otherRecordExistsWithSlug()` fait un
 * `withTrashed()`) — un soft delete aurait donc forcé le nouveau scraping à
 * générer des slugs suffixés ("-2", "-3"...) pour CHAQUE événement dont le
 * titre se re-scrape à l'identique, cassant les URLs déjà partagées/indexées
 * pour un simple nettoyage de doublons. Un force delete libère le slug
 * d'origine : le nouveau `scrape:events` régénère les mêmes URLs propres.
 * Les données sont de toute façon 100% reproductibles par un nouveau
 * scraping (c'est l'étape suivante), donc rien n'est réellement perdu.
 */
class ResetScrapedAgendaEvents extends Command
{
    protected $signature = 'content:reset-scraped-agenda-events {--dry-run : Affiche ce qui serait supprimé sans rien modifier}';

    protected $description = "Supprime définitivement tous les événements agenda scrapés (source='scraped'), avant un relance complet de scrape:events — ne touche jamais aux agendas manuels ni au cinéma";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('reset-scraped-agenda-events');

        $query = Event::query()->where('source', 'scraped');
        $total = $query->count();

        if ($dryRun) {
            $log->skipped("[dry-run] {$total} événement(s) source=scraped seraient supprimés définitivement.");
            $this->info("Dry-run : {$total} événement(s) source=scraped seraient supprimés définitivement.");
            $this->info($log->summary());

            return self::SUCCESS;
        }

        $deleted = 0;

        // withoutEvents() : suppression en lot, même garde-fou que les autres
        // commandes de migration (ne jamais déclencher
        // CloudflarePurgeObserver/GoogleIndexingObserver/RegeneratesSitemapObserver
        // par ligne — un seul passage de webcron/déploiement s'en chargera).
        Event::withoutEvents(function () use ($query, $log, &$deleted) {
            $query->chunkById(200, function ($events) use ($log, &$deleted) {
                foreach ($events as $event) {
                    $log->created("#{$event->id} \"{$event->title}\" (external_ref={$event->external_ref}) supprimé définitivement.");
                    $event->forceDelete();
                    $deleted++;
                }
            });
        });

        $this->info("{$deleted} événement(s) scrapé(s) supprimé(s) définitivement — lancer scrape:events pour repeupler.");
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
