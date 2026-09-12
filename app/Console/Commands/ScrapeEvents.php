<?php

namespace App\Console\Commands;

use App\Models\ScraperSource;
use App\Services\Newsletter\ScrapingDigestBuilder;
use App\Services\Scraping\ScraperRunner;
use Illuminate\Console\Command;

/**
 * Lance tous les scrapers agenda actifs (brief §6/§21). Même architecture
 * que `scrape:cinema` (voir son docblock) : chaque source (`scraper_sources`,
 * type `agenda`) instancie sa propre classe `driver_class` implémentant
 * `App\Services\Scraping\ScraperDriver` — voir `TheatreDeLaCiteDriver`,
 * la première source réelle (TECHNICAL_DOCUMENTATION.md §13).
 *
 * Un échec sur une source n'interrompt pas les autres. L'orchestration
 * réelle vit dans `ScraperRunner`, partagée avec `ScrapeCinema` — voir son
 * docblock.
 */
class ScrapeEvents extends Command
{
    protected $signature = 'scrape:events {--source= : Ne lancer qu\'une source précise (id)}';

    protected $description = 'Exécute les scrapers agenda actifs (mise à jour des événements par salle)';

    public function handle(ScraperRunner $runner): int
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
        $totals = ['created' => 0, 'updated' => 0];

        foreach ($sources as $source) {
            $this->info("=== {$source->name} ===");
            $result = $runner->run($source);

            if ($result['success']) {
                $stats = $result['stats'];
                $this->info("Trouvés: {$stats['found']}, créés: {$stats['created']}, mis à jour: {$stats['updated']}, ignorés: {$stats['skipped']}");
                $totals['created'] += $stats['created'];
                $totals['updated'] += $stats['updated'];
            } else {
                $this->error("Échec : {$result['error']}");
                $hasFailure = true;
            }
        }

        // Brouillon de newsletter (demande client, 12/09/2026, voir
        // App\Services\Newsletter\ScrapingDigestBuilder) — jamais envoyé
        // automatiquement, laissé à la validation d'un administrateur.
        ScrapingDigestBuilder::build('Agenda & théâtres', 'agenda', $totals);

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
