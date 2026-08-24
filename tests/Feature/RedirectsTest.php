<?php

namespace Tests\Feature;

use App\Models\MissedRedirect;
use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Redirections 301 (brief §15). Voir Controller::redirectOrAbort —
 * `Route::fallback()` seul ne suffit pas car la plupart des redirections
 * partagent la forme d'URL d'une route existante (voir docblock).
 */
class RedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_redirect_returns_301_to_target(): void
    {
        Redirect::create([
            'from_path' => '/annuaire/fiche/ancien-slug',
            'to_path' => '/annuaire/fiche/nouveau-slug',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->get('/annuaire/fiche/ancien-slug')
            ->assertRedirect('/annuaire/fiche/nouveau-slug')
            ->assertStatus(301);

        $this->assertSame(1, Redirect::first()->hits_count);
    }

    public function test_inactive_redirect_is_ignored(): void
    {
        Redirect::create([
            'from_path' => '/annuaire/fiche/ancien-slug',
            'to_path' => '/annuaire/fiche/nouveau-slug',
            'is_active' => false,
        ]);

        $this->get('/annuaire/fiche/ancien-slug')->assertNotFound();
    }

    public function test_unknown_path_returns_404(): void
    {
        $this->get('/annuaire/fiche/ceci-n-existe-pas')->assertNotFound();
        $this->get('/chemin-totalement-inconnu')->assertNotFound();
    }

    /**
     * `redirects:audit` (brief §15) n'a de sens que si les 404 réelles sont
     * journalisées quelque part — voir Controller::redirectOrAbort() et
     * RedirectFallbackController, MissedRedirect::record().
     */
    public function test_unknown_path_is_recorded_as_missed_redirect_and_increments_on_repeat(): void
    {
        $this->get('/annuaire/fiche/ceci-n-existe-pas')->assertNotFound();
        $this->get('/chemin-totalement-inconnu')->assertNotFound();

        $this->assertDatabaseHas(MissedRedirect::class, ['path' => '/annuaire/fiche/ceci-n-existe-pas', 'hits_count' => 1]);
        $this->assertDatabaseHas(MissedRedirect::class, ['path' => '/chemin-totalement-inconnu', 'hits_count' => 1]);

        // Un second passage sur le même chemin incrémente, ne duplique pas.
        $this->get('/annuaire/fiche/ceci-n-existe-pas')->assertNotFound();

        $this->assertSame(1, MissedRedirect::where('path', '/annuaire/fiche/ceci-n-existe-pas')->count());
        $this->assertSame(2, MissedRedirect::where('path', '/annuaire/fiche/ceci-n-existe-pas')->first()->hits_count);
    }

    public function test_known_redirect_does_not_get_recorded_as_missed(): void
    {
        Redirect::create(['from_path' => '/annuaire/fiche/ancien-slug', 'to_path' => '/annuaire/fiche/nouveau-slug', 'is_active' => true]);

        $this->get('/annuaire/fiche/ancien-slug');

        $this->assertDatabaseMissing(MissedRedirect::class, ['path' => '/annuaire/fiche/ancien-slug']);
    }

    public function test_redirects_audit_command_lists_frequent_misses_above_threshold(): void
    {
        MissedRedirect::create(['path' => '/rarement-vu', 'hits_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        MissedRedirect::create(['path' => '/souvent-vu', 'hits_count' => 10, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('redirects:audit', ['--min-hits' => 5])
            ->expectsOutputToContain('/souvent-vu')
            ->doesntExpectOutputToContain('/rarement-vu')
            ->assertSuccessful();
    }

    public function test_redirect_works_across_domains(): void
    {
        Redirect::create(['from_path' => '/agenda/ancien-evenement', 'to_path' => '/agenda/nouvel-evenement', 'is_active' => true]);
        Redirect::create(['from_path' => '/cinema/films/ancien-film', 'to_path' => '/cinema/films/nouveau-film', 'is_active' => true]);
        Redirect::create(['from_path' => '/actualites/ancienne-actu', 'to_path' => '/actualites/nouvelle-actu', 'is_active' => true]);

        $this->get('/agenda/ancien-evenement')->assertRedirect('/agenda/nouvel-evenement');
        $this->get('/cinema/films/ancien-film')->assertRedirect('/cinema/films/nouveau-film');
        $this->get('/actualites/ancienne-actu')->assertRedirect('/actualites/nouvelle-actu');
    }
}
