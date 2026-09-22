<?php

namespace Tests\Feature;

use App\Models\Cinema;
use App\Models\Movie;
use App\Models\Screening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Désactivation des liens de réservation pour les horaires sans occurrence
 * future (demande client, 22/09/2026 : "désactive les liens sur les
 * horaires des dates passées car les liens externes affichent une page
 * expirée") — voir resources/views/components/cinema/screening-time.blade.php.
 *
 * `ScreeningTime.weekday` est un jour RÉCURRENT (0-6), pas une date
 * calendaire — "passé" est donc évalué par rapport à la fin de fenêtre de
 * la Screening (`end_date`), pas par rapport à une date de projection
 * unique. Date figée pour des scénarios déterministes : lundi 21/09/2026
 * (dayOfWeek = 1).
 */
class CinemaPastScreeningLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 21)); // lundi
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekday_that_still_recurs_before_the_screening_ends_remains_clickable(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-past-1', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film A', 'slug' => 'film-a-past-1']);
        // Fenêtre allant jusqu'à dans 3 semaines : le mercredi (déjà passé
        // cette semaine) revient forcément avant la fin de la fenêtre.
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subWeek(), 'end_date' => now()->addWeeks(3),
        ]);
        // Mercredi (weekday=3) : déjà passé CETTE semaine (on est lundi), mais revient.
        $time = $screening->times()->create(['weekday' => 3, 'time' => '20:30:00', 'booking_url' => 'https://example.test/reserver']);

        $response = $this->get('/cinema/films/film-a-past-1')->assertOk();
        $response->assertSee('href="https://example.test/reserver"', false);
    }

    public function test_weekday_with_no_future_occurrence_before_the_screening_ends_is_disabled(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-past-2', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film B', 'slug' => 'film-b-past-2']);
        // La fenêtre se termine AUJOURD'HUI (lundi) — un horaire "mercredi"
        // n'aura plus jamais l'occasion de retomber dans la fenêtre.
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subWeeks(2), 'end_date' => now(),
        ]);
        $time = $screening->times()->create(['weekday' => 3, 'time' => '20:30:00', 'booking_url' => 'https://example.test/expire']);

        $response = $this->get('/cinema/films/film-b-past-2')->assertOk();
        $response->assertDontSee('href="https://example.test/expire"', false);
        // Reste visible en lecture seule (badge), juste plus cliquable.
        $response->assertSee('Mercredi 20:30');
    }

    public function test_todays_weekday_remains_clickable(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-past-3', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film C', 'slug' => 'film-c-past-3']);
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subWeek(), 'end_date' => now(),
        ]);
        // Aujourd'hui est un lundi (weekday=1) : cette occurrence n'est pas "passée".
        $time = $screening->times()->create(['weekday' => 1, 'time' => '18:00:00', 'booking_url' => 'https://example.test/today']);

        $response = $this->get('/cinema/films/film-c-past-3')->assertOk();
        $response->assertSee('href="https://example.test/today"', false);
    }

    public function test_screening_with_no_end_date_never_disables_the_link(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-past-4', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film D', 'slug' => 'film-d-past-4']);
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subMonth(), 'end_date' => null,
        ]);
        $time = $screening->times()->create(['weekday' => 3, 'time' => '20:30:00', 'booking_url' => 'https://example.test/no-end']);

        $response = $this->get('/cinema/films/film-d-past-4')->assertOk();
        $response->assertSee('href="https://example.test/no-end"', false);
    }

    public function test_disabled_link_also_applies_on_the_salle_page(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-past-5', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film E', 'slug' => 'film-e-past-5']);
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subWeeks(2), 'end_date' => now(),
        ]);
        $time = $screening->times()->create(['weekday' => 3, 'time' => '20:30:00', 'booking_url' => 'https://example.test/salle-expire']);

        $response = $this->get('/cinema/salles/gaumont-wilson-past-5')->assertOk();
        $response->assertDontSee('href="https://example.test/salle-expire"', false);
    }
}
