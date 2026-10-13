<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MissedRedirect;
use App\Models\Movie;
use App\Models\News;
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

    /**
     * Demande client (13/10/2026, rapport Search Console "Introuvable (404)",
     * ~1000 URLs) : formes d'URL legacy confirmées (`/film/...`, `/news/...`,
     * arborescence annuaire imbriquée), reconnues par
     * `RedirectFallbackController::resolveLegacyPattern()` UNIQUEMENT quand
     * la cible existe réellement — jamais une redirection devinée.
     */
    public function test_legacy_film_url_redirects_to_the_new_movie_page(): void
    {
        Movie::create(['title' => 'Cocorico', 'slug' => 'cocorico']);

        $this->get('/film/cocorico')->assertRedirect('/cinema/films/cocorico')->assertStatus(301);
    }

    public function test_legacy_film_salle_and_commentaire_urls_redirect_to_the_matching_anchor(): void
    {
        Movie::create(['title' => 'Garance', 'slug' => 'garance']);

        $this->get('/film/garance/salle')->assertRedirect('/cinema/films/garance#seances');
        $this->get('/film/garance/commentaire')->assertRedirect('/cinema/films/garance#avis');
    }

    public function test_legacy_film_url_stays_404_when_the_movie_no_longer_exists(): void
    {
        $this->get('/film/film-jamais-migre')->assertNotFound();
        $this->get('/film/film-jamais-migre/salle')->assertNotFound();
    }

    public function test_legacy_news_url_redirects_to_actualites(): void
    {
        News::create(['title' => 'Exposition', 'slug' => 'exposition-vercellotti', 'body' => 'Contenu.']);

        $this->get('/news/exposition-vercellotti')->assertRedirect('/actualites/exposition-vercellotti');
    }

    /**
     * Ancienne arborescence annuaire imbriquée (`/annuaire/cat/scat`, voir
     * old/client-app/nuxt.config.js) ET ses raccourcis thématiques
     * (`/sports/sous-categorie`) — seul le DERNIER segment (la catégorie la
     * plus précise) détermine la cible.
     */
    public function test_legacy_nested_annuaire_url_redirects_to_the_deepest_existing_category(): void
    {
        Category::create(['name' => 'Spa', 'slug' => 'spa', 'level' => 2, 'is_active' => true]);

        $this->get('/annuaire/beaute-spa-sauna/spa')->assertRedirect('/annuaire/spa');
    }

    public function test_legacy_theme_shortcut_url_redirects_to_the_matching_category(): void
    {
        Category::create(['name' => 'Insolite', 'slug' => 'insolite', 'level' => 2, 'is_active' => true]);

        $this->get('/sports/insolite')->assertRedirect('/annuaire/insolite');
    }

    public function test_legacy_nested_url_stays_404_when_the_last_segment_matches_no_category(): void
    {
        $this->get('/annuaire/categorie-disparue/sous-categorie-disparue')->assertNotFound();
    }

    /** Un `film`/`news` mort ne doit JAMAIS retomber sur la règle générique "dernier segment = catégorie". */
    public function test_legacy_film_url_is_never_caught_by_the_generic_category_rule(): void
    {
        Category::create(['name' => 'Cocorico', 'slug' => 'cocorico', 'level' => 0, 'is_active' => true]);

        // Aucun Movie "cocorico" — ne doit PAS rediriger vers /annuaire/cocorico.
        $this->get('/film/cocorico')->assertNotFound();
    }
}
