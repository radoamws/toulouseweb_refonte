<?php

namespace App\Console\Commands\Migration;

use App\Models\Event;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Demande client, 25/09/2026 : 3 exemples concrets d'adresse/date fausses sur
 * le front (ex. "Soudain, une île" en double avec des dates 2027 aberrantes,
 * "Air France..." avec l'adresse générique "Toulouse Métropole" au lieu de
 * "L'Envol des Pionniers") se sont révélés être des lignes `source='manual'`,
 * PAS `source='scraped'` — donc jamais touchées par
 * `content:reset-scraped-agenda-events` (§56) ni par le `scrape:events`
 * complet qui a suivi. Investigation : sur 18773 lignes `manual`, 6385 ont un
 * slug à suffixe numérique (doublon probable, ex. "...-2") et ~9857 ont une
 * `booking_url` pointant DIRECTEMENT vers un des 12 sites activement scrapés
 * aujourd'hui (7929 openagenda.com, 574 ardei-soft.com, 406 grand-rond.org,
 * 292 soticket.net, 257 casinosbarriere.com, 188 leventdessignes.fr, 159
 * theatre-cite.com, 33 theatregaronne.com, 18 grandsinterpretes, 1
 * odyssud.com) — quasi certainement d'anciennes données de SCRAPING migrées
 * depuis l'ancien site (`source` valant `manual` par défaut faute de
 * distinction dans le schéma legacy), pas de vraies saisies à la main.
 *
 * Portée strictement limitée à `events.source = 'manual'` ET
 * `booking_url LIKE '%domaine%'` pour un des domaines ci-dessous — ne touche
 * JAMAIS un `manual` sans booking_url reconnu (vraie saisie manuelle probable,
 * ex. un événement associatif sans lien de réservation externe), ni
 * `user_submitted`, ni le cinéma.
 *
 * Suppression DÉFINITIVE, même raisonnement que
 * `content:reset-scraped-agenda-events` (§56) : `events.slug` a une
 * contrainte unique en base et `HasSlug` évite les collisions même avec des
 * lignes soft-deleted (`withTrashed()`) — un soft delete forcerait le
 * scraping suivant à générer des slugs suffixés pour rien. Un événement
 * encore d'actualité sur son site source sera recréé, propre, par
 * `scrape:events` juste après ; un événement disparu du site source ne
 * revient pas (normal, ce n'est plus d'actualité).
 */
class ResetLegacyManualScrapedEvents extends Command
{
    protected $signature = 'content:reset-legacy-manual-scraped-events {--dry-run : Affiche ce qui serait supprimé sans rien modifier}';

    protected $description = "Supprime définitivement les événements source='manual' dont la booking_url pointe vers un site activement scrapé (données de scraping legacy mal étiquetées lors de la migration)";

    /** Domaines des 12 sources agenda actives — voir docblock de classe. */
    private const SCRAPED_SOURCE_DOMAINS = [
        'openagenda.com',
        'ardei-soft.com',
        'grand-rond.org',
        'soticket.net',
        'casinosbarriere.com',
        'leventdessignes.fr',
        'theatre-cite.com',
        'theatregaronne.com',
        'grandsinterpretes',
        'odyssud.com',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('reset-legacy-manual-scraped-events');

        $query = Event::query()->where('source', 'manual')->where(function ($q) {
            foreach (self::SCRAPED_SOURCE_DOMAINS as $domain) {
                $q->orWhere('booking_url', 'like', "%{$domain}%");
            }
        });
        $total = $query->count();

        if ($dryRun) {
            $log->skipped("[dry-run] {$total} événement(s) manual/scraping-legacy seraient supprimés définitivement.");
            $this->info("Dry-run : {$total} événement(s) manual/scraping-legacy seraient supprimés définitivement.");
            $this->info($log->summary());

            return self::SUCCESS;
        }

        $deleted = 0;

        // withoutEvents() : même garde-fou que les autres commandes de
        // migration en lot (ne jamais déclencher CloudflarePurgeObserver/
        // GoogleIndexingObserver/RegeneratesSitemapObserver par ligne).
        Event::withoutEvents(function () use ($query, $log, &$deleted) {
            $query->chunkById(200, function ($events) use ($log, &$deleted) {
                foreach ($events as $event) {
                    $log->created("#{$event->id} \"{$event->title}\" (booking_url={$event->booking_url}) supprimé définitivement.");
                    $event->forceDelete();
                    $deleted++;
                }
            });
        });

        $this->info("{$deleted} événement(s) manual/scraping-legacy supprimé(s) définitivement — lancer scrape:events pour repeupler ce qui est encore d'actualité.");
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
