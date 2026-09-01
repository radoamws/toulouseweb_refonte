<?php

namespace App\Services\Scraping;

use App\Models\ScraperRun;
use App\Models\ScraperSource;

/**
 * Orchestration commune à l'exécution d'une source de scraping (cinéma ou
 * agenda) : cycle de vie `ScraperRun` (running -> success/partial/failed),
 * mise à jour de `scraper_sources.last_run_at`/`last_status`. Extrait de
 * `ScrapeCinema`/`ScrapeEvents` (jusqu'ici dupliqué à l'identique dans les
 * deux commandes) pour être réutilisable par une 3e origine d'exécution :
 * le bouton "Scraper" de `CinemaResource` (brief, demande client) —
 * lancement manuel d'une seule source depuis l'admin, avec le même suivi
 * `ScraperRun` qu'un lancement cron, pas un mécanisme parallèle.
 */
class ScraperRunner
{
    /**
     * @return array{success: bool, stats: array, error: ?string} `stats` a
     *                                                             les clés found/created/updated/skipped (0 partout si `success` est faux).
     */
    public function run(ScraperSource $source): array
    {
        $run = ScraperRun::create([
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $driverClass = $source->driver_class;
            if (! is_a($driverClass, ScraperDriver::class, true)) {
                throw new \RuntimeException("La classe {$driverClass} n'implémente pas ScraperDriver.");
            }

            /** @var ScraperDriver $driver */
            $driver = app($driverClass);
            $stats = $driver->run($source);

            $status = ($stats['skipped'] ?? 0) > 0 && ($stats['created'] ?? 0) === 0 && ($stats['updated'] ?? 0) === 0
                ? 'partial'
                : 'success';

            $run->update([
                'finished_at' => now(),
                'status' => $status,
                'items_found' => $stats['found'] ?? 0,
                'items_created' => $stats['created'] ?? 0,
                'items_updated' => $stats['updated'] ?? 0,
                'items_skipped' => $stats['skipped'] ?? 0,
            ]);

            $source->update(['last_run_at' => now(), 'last_status' => $status]);

            return ['success' => true, 'stats' => $stats, 'error' => null];
        } catch (\Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $source->update(['last_run_at' => now(), 'last_status' => 'failed']);

            return ['success' => false, 'stats' => ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0], 'error' => $e->getMessage()];
        }
    }
}
