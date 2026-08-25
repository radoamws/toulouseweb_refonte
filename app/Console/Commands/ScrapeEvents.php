<?php

namespace App\Console\Commands;

use App\Models\ScraperRun;
use App\Models\ScraperSource;
use Illuminate\Console\Command;

/**
 * Lance tous les scrapers agenda actifs (brief §6/§21). Même architecture
 * que `scrape:cinema` (voir son docblock) : chaque source (`scraper_sources`,
 * type `agenda`) instancie sa propre classe `driver_class` implémentant
 * `App\Services\Scraping\ScraperDriver` — voir `TheatreDeLaCiteDriver`,
 * la première source réelle (TECHNICAL_DOCUMENTATION.md §13).
 *
 * Un échec sur une source n'interrompt pas les autres.
 */
class ScrapeEvents extends Command
{
    protected $signature = 'scrape:events {--source= : Ne lancer qu\'une source précise (id)}';

    protected $description = 'Exécute les scrapers agenda actifs (mise à jour des événements par salle)';

    public function handle(): int
    {
        $sources = ScraperSource::query()
            ->where('type', 'agenda')
            ->where('is_active', true)
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('Aucune source de scraping agenda active.');

            return self::SUCCESS;
        }

        $hasFailure = false;

        foreach ($sources as $source) {
            $this->info("=== {$source->name} ===");
            $hasFailure = $this->runSource($source) ? $hasFailure : true;
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    protected function runSource(ScraperSource $source): bool
    {
        $run = ScraperRun::create([
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $driverClass = $source->driver_class;
            if (! is_a($driverClass, \App\Services\Scraping\ScraperDriver::class, true)) {
                throw new \RuntimeException("La classe {$driverClass} n'implémente pas ScraperDriver.");
            }

            /** @var \App\Services\Scraping\ScraperDriver $driver */
            $driver = app($driverClass);
            $stats = $driver->run($source);

            $status = $stats['skipped'] > 0 && $stats['created'] === 0 && $stats['updated'] === 0 ? 'partial' : 'success';

            $run->update([
                'finished_at' => now(),
                'status' => $status,
                'items_found' => $stats['found'] ?? 0,
                'items_created' => $stats['created'] ?? 0,
                'items_updated' => $stats['updated'] ?? 0,
                'items_skipped' => $stats['skipped'] ?? 0,
            ]);

            $source->update(['last_run_at' => now(), 'last_status' => $status]);

            $this->info("Trouvés: {$stats['found']}, créés: {$stats['created']}, mis à jour: {$stats['updated']}, ignorés: {$stats['skipped']}");

            return true;
        } catch (\Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $source->update(['last_run_at' => now(), 'last_status' => 'failed']);

            $this->error("Échec : {$e->getMessage()}");

            return false;
        }
    }
}
