<?php

namespace App\Console\Commands;

use App\Models\ScraperRun;
use App\Models\ScraperSource;
use Illuminate\Console\Command;

/**
 * Lance tous les scrapers cinéma actifs (brief §7/§9/§21). Chaque source
 * (`scraper_sources`, administrable via ScraperSourceResource) instancie sa
 * propre classe (`driver_class`) implémentant `App\Services\Scraping\ScraperDriver`
 * — voir PatheGaumontDriver pour la première source réelle (Gaumont Wilson).
 *
 * Un échec sur une source n'interrompt pas les autres (chacune a son propre
 * `scraper_runs`, consultable dans l'admin pour diagnostiquer).
 */
class ScrapeCinema extends Command
{
    protected $signature = 'scrape:cinema {--source= : Ne lancer qu\'une source précise (id)}';

    protected $description = 'Exécute les scrapers cinéma actifs (mise à jour des fiches film + associations salle)';

    public function handle(): int
    {
        $sources = ScraperSource::query()
            ->where('type', 'cinema')
            ->where('is_active', true)
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('Aucune source de scraping cinéma active.');

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
