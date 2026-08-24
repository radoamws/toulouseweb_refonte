<?php

namespace Tests\Feature;

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
