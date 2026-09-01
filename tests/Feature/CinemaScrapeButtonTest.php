<?php

namespace Tests\Feature;

use App\Filament\Resources\CinemaResource\Pages\ListCinemas;
use App\Models\Cinema;
use App\Models\Movie;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\User;
use App\Services\Scraping\Cinema\AllocineDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bouton "Scraper" par salle sur le listing admin des cinémas (demande
 * client) — lance `App\Services\Scraping\ScraperRunner` (extrait de
 * `ScrapeCinema`/`ScrapeEvents`, désormais partagé par les 3 origines
 * d'exécution) pour la seule source de CETTE salle, avec le même suivi
 * `ScraperRun` qu'un lancement cron. Réponse HTTP simulée fidèle à AlloCiné
 * (voir docblock de `ScrapeCinemaTest`, même fixture minimale reprise ici).
 * Le "loader" pendant l'exécution est natif à Filament (état de chargement
 * Livewire sur le bouton d'action) — non testable en HTTP statique, pas de
 * test dédié pour cette partie.
 */
class CinemaScrapeButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    protected function fakeAllocineResponse(): array
    {
        return [
            'results' => [[
                'movie' => [
                    'internalId' => 999,
                    'title' => 'Film Test Bouton',
                    'genres' => [],
                    'synopsis' => 'x',
                    'poster' => ['url' => 'https://cdn.example.test/poster.jpg'],
                    'credits' => [],
                    'cast' => ['edges' => []],
                    'data' => ['productionYear' => 2026],
                ],
                'showtimes' => ['dubbed' => [[
                    'diffusionVersion' => 'ORIGINAL',
                    'startsAt' => now()->setTime(20, 0)->toIso8601String(),
                    'data' => ['ticketing' => []],
                ]]],
            ]],
        ];
    }

    public function test_scrape_button_runs_the_scraper_for_that_cinema_only(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $otherCinema = Cinema::create(['name' => 'ABC', 'slug' => 'abc', 'is_active' => true]);
        $source = ScraperSource::create([
            'name' => 'AlloCiné — Gaumont Wilson',
            'type' => 'cinema',
            'driver_class' => AllocineDriver::class,
            'config' => ['allocine_theater_id' => 'P0057', 'cinema_id' => $cinema->id, 'window_days' => 1],
            'is_active' => true,
        ]);
        $otherSource = ScraperSource::create([
            'name' => 'AlloCiné — ABC',
            'type' => 'cinema',
            'driver_class' => AllocineDriver::class,
            'config' => ['allocine_theater_id' => 'P0071', 'cinema_id' => $otherCinema->id, 'window_days' => 1],
            'is_active' => true,
        ]);

        Http::fake([
            'allocine.fr/_/showtimes/theater-P0057/d-*' => Http::response($this->fakeAllocineResponse()),
        ]);

        Livewire::test(ListCinemas::class)
            ->callTableAction('scrape', $cinema)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(Movie::where('external_ref', '999')->first());

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('success', $run->status);
        $this->assertSame('success', $source->fresh()->last_status);

        // La salle B n'a PAS été scrapée (pas d'appel réseau vers son endpoint).
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'theater-P0071'));
        $this->assertNull(ScraperRun::where('source_id', $otherSource->id)->first());
    }

    public function test_scrape_button_is_hidden_when_cinema_has_no_source(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $cinema = Cinema::create(['name' => 'Salle Sans Source', 'slug' => 'salle-sans-source', 'is_active' => true]);

        Livewire::test(ListCinemas::class)
            ->assertTableActionHidden('scrape', $cinema);
    }

    public function test_scrape_button_shows_failure_notification_on_upstream_error(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        ScraperSource::create([
            'name' => 'AlloCiné — Gaumont Wilson',
            'type' => 'cinema',
            'driver_class' => AllocineDriver::class,
            'config' => ['allocine_theater_id' => 'P0057', 'cinema_id' => $cinema->id, 'window_days' => 1],
            'is_active' => true,
        ]);

        Http::fake(['allocine.fr/*' => Http::response('Erreur serveur', 500)]);

        Livewire::test(ListCinemas::class)
            ->callTableAction('scrape', $cinema)
            ->assertNotified('Échec du scraping');
    }
}
