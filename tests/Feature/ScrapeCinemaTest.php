<?php

namespace Tests\Feature;

use App\Models\Cinema;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Cinema\PatheGaumontDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le scraper Pathé-Gaumont (brief §7/§9) — voir docblock de PatheGaumontDriver
 * pour l'origine des noms de champs (code legacy réel, l'API elle-même
 * n'étant pas accessible depuis ce sandbox). Réponses HTTP simulées via
 * Http::fake() avec des champs fidèles à ce que le code legacy consommait.
 */
class ScrapeCinemaTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(Cinema $cinema, array $configOverrides = []): ScraperSource
    {
        return ScraperSource::create([
            'name' => 'Pathé-Gaumont Wilson',
            'type' => 'cinema',
            'driver_class' => PatheGaumontDriver::class,
            'config' => array_merge([
                'cinema_api_slug' => 'cinema-gaumont-wilson',
                'cinema_id' => $cinema->id,
                'window_days' => 7,
            ], $configOverrides),
            'is_active' => true,
        ]);
    }

    public function test_new_film_is_created_and_screening_linked_to_cinema(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $source = $this->makeSource($cinema);

        Http::fake([
            'cinemaspathegaumont.com/api/cinema/*/shows*' => Http::response([
                'shows' => [
                    'le-comte-de-toulouse' => [
                        'days' => [
                            now()->addDay()->toDateString() => ['sessions' => []],
                            now()->addDays(2)->toDateString() => ['sessions' => []],
                        ],
                    ],
                ],
            ]),
            'cinemaspathegaumont.com/api/show/le-comte-de-toulouse*' => Http::response([
                'title' => 'Le Comte de Toulouse',
                'slug' => 'le-comte-de-toulouse',
                'directors' => 'Jane Réalisatrice',
                'actors' => 'Acteur Un, Actrice Deux',
                'nationality' => 'Française',
                'genres' => ['Drame', 'Comédie'],
                'duration' => 125,
                'releaseAt' => [now()->toIso8601String()],
                'synopsis' => 'Un synopsis de test.',
                'posterPath' => ['md' => 'https://cdn.example.test/poster-md.jpg', 'lg' => 'https://cdn.example.test/poster-lg.jpg'],
                'distribution' => 'Distributeur Test',
            ]),
        ]);

        $exitCode = $this->artisan('scrape:cinema')->run();

        $this->assertSame(0, $exitCode);

        $movie = Movie::where('external_ref', 'le-comte-de-toulouse')->first();
        $this->assertNotNull($movie);
        $this->assertSame('Le Comte de Toulouse', $movie->title);
        $this->assertSame('Jane Réalisatrice', $movie->director);
        $this->assertSame('Drame, Comédie', $movie->genres);
        $this->assertSame(125, $movie->duration_minutes);
        $this->assertSame('https://cdn.example.test/poster-md.jpg', $movie->poster);

        $screening = Screening::where('cinema_id', $cinema->id)->where('movie_id', $movie->id)->first();
        $this->assertNotNull($screening, 'Une association salle/film aurait dû être créée.');

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_found);
        $this->assertSame(1, $run->items_created);
    }

    public function test_existing_film_is_updated_not_duplicated(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema);

        Movie::create([
            'title' => 'Ancien titre', 'slug' => 'le-comte-de-toulouse',
            'external_ref' => 'le-comte-de-toulouse', 'synopsis' => 'Ancien synopsis',
        ]);

        Http::fake([
            'cinemaspathegaumont.com/api/cinema/*/shows*' => Http::response([
                'shows' => ['le-comte-de-toulouse' => ['days' => [now()->addDay()->toDateString() => []]]],
            ]),
            'cinemaspathegaumont.com/api/show/le-comte-de-toulouse*' => Http::response([
                'title' => 'Le Comte de Toulouse', 'synopsis' => 'Synopsis mis à jour',
            ]),
        ]);

        $this->artisan('scrape:cinema')->run();

        $this->assertSame(1, Movie::where('external_ref', 'le-comte-de-toulouse')->count());
        $this->assertSame('Synopsis mis à jour', Movie::where('external_ref', 'le-comte-de-toulouse')->first()->synopsis);
    }

    public function test_film_with_no_dates_in_window_is_skipped(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema);

        Http::fake([
            'cinemaspathegaumont.com/api/cinema/*/shows*' => Http::response([
                'shows' => ['film-trop-lointain' => ['days' => [now()->addDays(60)->toDateString() => []]]],
            ]),
        ]);

        $this->artisan('scrape:cinema')->run();

        $this->assertDatabaseMissing('movies', ['external_ref' => 'film-trop-lointain']);
        $run = ScraperRun::first();
        $this->assertSame(1, $run->items_skipped);
    }

    public function test_upstream_failure_marks_run_as_failed_without_crashing(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $source = $this->makeSource($cinema);

        Http::fake([
            'cinemaspathegaumont.com/*' => Http::response('Access Denied', 403),
        ]);

        $exitCode = $this->artisan('scrape:cinema')->run();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', ScraperRun::where('source_id', $source->id)->first()->status);
    }

    public function test_inactive_source_is_not_run(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema)->update(['is_active' => false]);

        Http::fake();

        $this->artisan('scrape:cinema')->run();

        Http::assertNothingSent();
    }
}
