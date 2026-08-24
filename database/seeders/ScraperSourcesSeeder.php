<?php

namespace Database\Seeders;

use App\Models\Cinema;
use App\Models\ScraperSource;
use App\Services\Scraping\Cinema\PatheGaumontDriver;
use Illuminate\Database\Seeder;

/**
 * Sources de scraping connues (brief §7/§21). À exécuter une fois par
 * environnement : `php artisan db:seed --class=ScraperSourcesSeeder`.
 */
class ScraperSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $gaumontWilson = Cinema::where('slug', 'pathe-gaumont-wilson')->first();

        if ($gaumontWilson) {
            ScraperSource::updateOrCreate(
                ['name' => 'Pathé-Gaumont Wilson'],
                [
                    'type' => 'cinema',
                    'driver_class' => PatheGaumontDriver::class,
                    'config' => [
                        'cinema_api_slug' => 'cinema-gaumont-wilson',
                        'cinema_id' => $gaumontWilson->id,
                        'window_days' => 7,
                    ],
                    'is_active' => true,
                ]
            );
        }
    }
}
