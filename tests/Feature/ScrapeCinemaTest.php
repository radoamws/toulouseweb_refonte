<?php

namespace Tests\Feature;

use App\Models\Cinema;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\ScreeningTime;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Cinema\AllocineDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le scraper cinéma AlloCiné (brief §7/§9) — voir docblock de AllocineDriver
 * pour l'origine des noms de champs (code source réel du contrôleur legacy,
 * l'API elle-même n'étant pas accessible depuis ce sandbox). Réponses HTTP
 * simulées via Http::fake() avec une forme fidèle à ce que
 * `https://www.allocine.fr/_/showtimes/theater-{id}/d-{date}/` renvoie
 * d'après ce code.
 */
class ScrapeCinemaTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(Cinema $cinema, array $configOverrides = []): ScraperSource
    {
        return ScraperSource::create([
            'name' => 'AlloCiné — '.$cinema->name,
            'type' => 'cinema',
            'driver_class' => AllocineDriver::class,
            'config' => array_merge([
                'allocine_theater_id' => 'P0057',
                'cinema_id' => $cinema->id,
                'window_days' => 1,
            ], $configOverrides),
            'is_active' => true,
        ]);
    }

    protected function fakeDayResponse(array $movieOverrides = [], array $showtimeOverrides = []): array
    {
        $movie = array_merge([
            'internalId' => 123456,
            'title' => 'Le Comte de Toulouse',
            'genres' => [['translate' => 'Drame'], ['translate' => 'Comédie']],
            'synopsis' => 'Un synopsis de test.',
            'poster' => ['url' => 'https://cdn.example.test/poster.jpg'],
            'credits' => [
                ['position' => ['name' => 'Réalisation'], 'person' => ['firstName' => 'Jane', 'lastName' => 'Réalisatrice']],
            ],
            'cast' => ['edges' => [
                ['node' => ['actor' => ['firstName' => 'Acteur', 'lastName' => 'Un']]],
            ]],
            'data' => ['productionYear' => 2026],
        ], $movieOverrides);

        $showtime = array_merge([
            'diffusionVersion' => 'ORIGINAL',
            'startsAt' => now()->setTime(20, 30)->toIso8601String(),
            'data' => ['ticketing' => [
                ['provider' => 'default', 'urls' => ['https://booking.example.test/seance-1']],
            ]],
        ], $showtimeOverrides);

        return [
            'results' => [
                ['movie' => $movie, 'showtimes' => ['dubbed' => [$showtime]]],
            ],
        ];
    }

    public function test_new_film_is_created_and_screening_time_linked_to_cinema(): void
    {
        $cinema = Cinema::create(['name' => 'Pathé - Gaumont Wilson', 'slug' => 'pathe-gaumont-wilson', 'is_active' => true]);
        $source = $this->makeSource($cinema);

        Http::fake([
            'allocine.fr/_/showtimes/theater-P0057/d-*' => Http::response($this->fakeDayResponse()),
        ]);

        $exitCode = $this->artisan('scrape:cinema')->run();

        $this->assertSame(0, $exitCode);

        $movie = Movie::where('external_ref', '123456')->first();
        $this->assertNotNull($movie);
        $this->assertSame('Le Comte de Toulouse', $movie->title);
        $this->assertSame('Jane Réalisatrice', $movie->director);
        $this->assertSame('Acteur Un', $movie->cast);
        $this->assertSame('Drame, Comédie', $movie->genres);
        $this->assertSame('https://cdn.example.test/poster.jpg', $movie->poster);

        $screening = Screening::where('cinema_id', $cinema->id)->where('movie_id', $movie->id)->first();
        $this->assertNotNull($screening, 'Une association salle/film aurait dû être créée.');

        $time = ScreeningTime::where('screening_id', $screening->id)->first();
        $this->assertNotNull($time);
        $this->assertSame('20:30', $time->time);
        $this->assertSame('https://booking.example.test/seance-1', $time->booking_url);

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_found);
        $this->assertSame(1, $run->items_created);
    }

    public function test_existing_migrated_movie_is_matched_by_slug_and_backfilled_not_duplicated(): void
    {
        $cinema = Cinema::create(['name' => 'Pathé - Gaumont Wilson', 'slug' => 'pathe-gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema);

        // Simule un film migré depuis t_cine_film (legacy_id renseigné, external_ref absent).
        Movie::create([
            'title' => 'Le Comte de Toulouse', 'slug' => 'le-comte-de-toulouse',
            'legacy_id' => 42, 'synopsis' => 'Ancien synopsis',
        ]);

        Http::fake([
            'allocine.fr/_/showtimes/theater-P0057/d-*' => Http::response($this->fakeDayResponse()),
        ]);

        $this->artisan('scrape:cinema')->run();

        $this->assertSame(1, Movie::where('slug', 'le-comte-de-toulouse')->count());
        $movie = Movie::where('slug', 'le-comte-de-toulouse')->first();
        $this->assertSame('123456', $movie->external_ref);
        $this->assertSame(42, $movie->legacy_id);
        $this->assertSame('Un synopsis de test.', $movie->synopsis);
    }

    public function test_rerun_is_idempotent_and_refreshes_booking_url(): void
    {
        $cinema = Cinema::create(['name' => 'Pathé - Gaumont Wilson', 'slug' => 'pathe-gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema);

        // Http::fake() empile les règles (la première règle enregistrée qui
        // matche une URL gagne) : pour simuler deux exécutions successives
        // avec des réponses différentes sur la même URL, il faut une vraie
        // séquence plutôt que deux appels à Http::fake().
        Http::fakeSequence('allocine.fr/_/showtimes/theater-P0057/d-*')
            ->push($this->fakeDayResponse())
            ->push($this->fakeDayResponse([], [
                'data' => ['ticketing' => [
                    ['provider' => 'default', 'urls' => ['https://booking.example.test/seance-mise-a-jour']],
                ]],
            ]));

        $this->artisan('scrape:cinema')->run();
        $this->artisan('scrape:cinema')->run();

        $this->assertSame(1, Movie::where('external_ref', '123456')->count());
        $this->assertSame(1, Screening::where('cinema_id', $cinema->id)->count());
        $time = ScreeningTime::first();
        $this->assertSame(1, ScreeningTime::count());
        $this->assertSame('https://booking.example.test/seance-mise-a-jour', $time->booking_url);
    }

    public function test_entry_without_movie_is_skipped(): void
    {
        $cinema = Cinema::create(['name' => 'Pathé - Gaumont Wilson', 'slug' => 'pathe-gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema);

        Http::fake([
            'allocine.fr/_/showtimes/theater-P0057/d-*' => Http::response(['results' => [
                ['movie' => null, 'showtimes' => []],
            ]]),
        ]);

        $this->artisan('scrape:cinema')->run();

        $run = ScraperRun::first();
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(0, Movie::count());
    }

    public function test_upstream_failure_marks_run_as_failed_without_crashing(): void
    {
        $cinema = Cinema::create(['name' => 'Pathé - Gaumont Wilson', 'slug' => 'pathe-gaumont-wilson', 'is_active' => true]);
        $source = $this->makeSource($cinema);

        Http::fake([
            'allocine.fr/*' => Http::response('Access Denied', 403),
        ]);

        $exitCode = $this->artisan('scrape:cinema')->run();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', ScraperRun::where('source_id', $source->id)->first()->status);
    }

    public function test_inactive_source_is_not_run(): void
    {
        $cinema = Cinema::create(['name' => 'Pathé - Gaumont Wilson', 'slug' => 'pathe-gaumont-wilson', 'is_active' => true]);
        $this->makeSource($cinema)->update(['is_active' => false]);

        Http::fake();

        $this->artisan('scrape:cinema')->run();

        Http::assertNothingSent();
    }
}
