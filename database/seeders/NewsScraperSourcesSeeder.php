<?php

namespace Database\Seeders;

use App\Models\NewsCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\News\LeJournalToulousainDriver;
use Illuminate\Database\Seeder;

/**
 * Source de scraping d'actualités (demande client, 20/11/2026). À exécuter
 * une fois par environnement : `php artisan db:seed --class=NewsScraperSourcesSeeder`
 * — même convention que `AgendaScraperSourcesSeeder`/`ScraperSourcesSeeder`.
 */
class NewsScraperSourcesSeeder extends Seeder
{
    public function run(): void
    {
        // Catégorie cible des actualités scrapées (voir config `category_slug`
        // ci-dessous) — `firstOrCreate` : peut déjà exister (legacy migré) ou
        // non selon l'environnement, jamais dupliquée.
        NewsCategory::firstOrCreate(['slug' => 'vie-locale'], ['name' => 'Vie locale']);

        ScraperSource::updateOrCreate(
            ['name' => 'Le Journal Toulousain'],
            [
                'type' => 'news',
                'driver_class' => LeJournalToulousainDriver::class,
                'config' => [
                    'feed_url' => 'https://www.lejournaltoulousain.fr/occitanie/haute-garonne/toulouse/feed/',
                    'category_slug' => 'vie-locale',
                ],
                'is_active' => true,
            ]
        );
    }
}
