<?php

namespace App\Services\Scraping;

use App\Models\ScraperSource;

/**
 * Contrat commun à tous les scrapers (agenda, cinéma...), voir brief §7 et
 * TECHNICAL_DOCUMENTATION.md §9 (`scraper_sources.driver_class`).
 */
interface ScraperDriver
{
    /**
     * Exécute le scraping pour une source donnée et retourne des compteurs
     * exploitables pour `scraper_runs` : ['found' => int, 'created' => int,
     * 'updated' => int, 'skipped' => int].
     */
    public function run(ScraperSource $source): array;
}
