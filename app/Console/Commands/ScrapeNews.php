<?php

namespace App\Console\Commands;

use App\Models\ScraperSource;
use App\Services\Newsletter\ScrapingDigestBuilder;
use App\Services\Scraping\ScraperRunner;
use Illuminate\Console\Command;

/**
 * Lance tous les scrapers d'actualités actifs (demande client, 20/11/2026 :
 * "les news ne sont pas à jour") — même architecture que `scrape:cinema`/
 * `scrape:events` (voir leur docblock respectif) : chaque source
 * (`scraper_sources`, type `news`) instancie sa propre classe
 * `driver_class` implémentant `App\Services\Scraping\ScraperDriver` — voir
 * `App\Services\Scraping\News\LeJournalToulousainDriver`, la première
 * source réelle (TECHNICAL_DOCUMENTATION.md §82).
 *
 * Un échec sur une source n'interrompt pas les autres. L'orchestration
 * réelle vit dans `ScraperRunner`, partagée avec `ScrapeCinema`/`ScrapeEvents`.
 */
class ScrapeNews extends Command
{
    protected $signature = 'scrape:news {--source= : Ne lancer qu\'une source précise (id)}';

    protected $description = "Exécute les scrapers d'actualités actifs (mise à jour des actualités locales)";

    public function handle(ScraperRunner $runner): int
    {
        $sources = ScraperSource::query()
            ->where('type', 'news')
            ->where('is_active', true)
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($sources->isEmpty()) {
            $this->warn("Aucune source de scraping d'actualités active.");

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

        // Brouillon de newsletter (voir ScrapeEvents::handle()) — jamais
        // envoyé automatiquement, laissé à la validation d'un administrateur.
        ScrapingDigestBuilder::build('Actualités', 'news', $totals);

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
