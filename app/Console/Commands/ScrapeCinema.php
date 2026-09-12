<?php

namespace App\Console\Commands;

use App\Models\ScraperSource;
use App\Services\Newsletter\ScrapingDigestBuilder;
use App\Services\Scraping\ScraperRunner;
use Illuminate\Console\Command;

/**
 * Lance tous les scrapers cinéma actifs (brief §7/§9/§21). Chaque source
 * (`scraper_sources`, administrable via ScraperSourceResource) instancie sa
 * propre classe (`driver_class`) implémentant `App\Services\Scraping\ScraperDriver`
 * — voir AllocineDriver, une source par salle (`ScraperSourcesSeeder`).
 *
 * Un échec sur une source n'interrompt pas les autres (chacune a son propre
 * `scraper_runs`, consultable dans l'admin pour diagnostiquer).
 *
 * L'orchestration réelle (cycle de vie `ScraperRun`, mise à jour de
 * `last_run_at`/`last_status`) vit dans `ScraperRunner`, partagée avec
 * `ScrapeEvents` ET le bouton "Scraper" de `CinemaResource` (lancement
 * manuel d'une salle depuis l'admin) — un seul endroit qui décide de ce
 * qu'est une exécution "réussie"/"partielle"/"échouée".
 */
class ScrapeCinema extends Command
{
    protected $signature = 'scrape:cinema {--source= : Ne lancer qu\'une source précise (id)}';

    protected $description = 'Exécute les scrapers cinéma actifs (mise à jour des fiches film + associations salle)';

    public function handle(ScraperRunner $runner): int
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
        ScrapingDigestBuilder::build('Cinéma', 'cinema', $totals);

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
