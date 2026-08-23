<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Category;
use App\Models\Cinema;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Language;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\Screening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie les pages publiques annuaire/agenda/cinéma construites en Phase 6-8
 * (index + fiche détail) — voir TECHNICAL_DOCUMENTATION.md §12.
 */
class PublicContentPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_annuaire_index_renders(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        $listing = Listing::create([
            'title' => 'Le Bon Cassoulet', 'slug' => 'le-bon-cassoulet',
            'tier' => 'paid', 'status' => 'published', 'city' => 'Toulouse',
        ]);
        $listing->categories()->attach($category);

        $this->get('/annuaire')->assertOk()->assertSee('Le Bon Cassoulet');
        $this->get('/annuaire/restaurants')->assertOk()->assertSee('Le Bon Cassoulet');
    }

    public function test_annuaire_show_renders_paid_and_free_tiers_differently(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);

        $paid = Listing::create([
            'title' => 'Fiche Payante', 'slug' => 'fiche-payante', 'tier' => 'paid', 'status' => 'published',
            'description' => 'Description riche', 'website' => 'https://example.test',
        ]);
        $paid->categories()->attach($category);

        $free = Listing::create([
            'title' => 'Fiche Gratuite', 'slug' => 'fiche-gratuite', 'tier' => 'free', 'status' => 'published',
            'address' => '1 rue de Test', 'phone' => '0500000000',
        ]);
        $free->categories()->attach($category);

        $this->get('/annuaire/fiche/fiche-payante')->assertOk()->assertSee('Description riche')->assertSee('Visiter le site');
        $this->get('/annuaire/fiche/fiche-gratuite')->assertOk()->assertSee('1 rue de Test')->assertDontSee('Visiter le site');
    }

    public function test_unpublished_listing_is_not_accessible(): void
    {
        Listing::create(['title' => 'Brouillon', 'slug' => 'brouillon', 'tier' => 'free', 'status' => 'draft']);

        $this->get('/annuaire/fiche/brouillon')->assertNotFound();
    }

    public function test_agenda_index_and_theatre_category_render(): void
    {
        $theatre = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $area = Area::create(['name' => 'Théâtre du Capitole', 'slug' => 'theatre-du-capitole']);
        $event = Event::create([
            'title' => 'Le Malade Imaginaire', 'slug' => 'le-malade-imaginaire',
            'area_id' => $area->id, 'status' => 'published', 'start_date' => now()->addDays(3),
        ]);
        $event->categories()->attach($theatre);

        $this->get('/agenda')->assertOk()->assertSee('Le Malade Imaginaire');
        $this->get('/agenda/theatre')->assertOk()->assertSee('Le Malade Imaginaire')->assertSee('Théâtre');
    }

    public function test_agenda_slug_resolves_event_when_not_a_category(): void
    {
        $event = Event::create([
            'title' => 'Concert Test', 'slug' => 'concert-test',
            'status' => 'published', 'start_date' => now()->addDay(),
        ]);

        $this->get('/agenda/concert-test')->assertOk()->assertSee('Concert Test');
    }

    public function test_cinema_index_and_movie_and_salle_pages_render(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Le Comte de Toulouse', 'slug' => 'le-comte-de-toulouse']);
        $language = Language::create(['name' => 'VF']);
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id, 'language_id' => $language->id,
            'start_date' => now()->subDay(), 'end_date' => now()->addWeek(),
        ]);
        $screening->times()->create(['weekday' => 1, 'time' => '20:30:00']);

        $this->get('/cinema')->assertOk()->assertSee('Le Comte de Toulouse');
        $this->get('/cinema/films/le-comte-de-toulouse')->assertOk()->assertSee('Gaumont Wilson');
        $this->get('/cinema/salles/gaumont-wilson')->assertOk()->assertSee('Le Comte de Toulouse');
    }

    public function test_cinema_movie_with_no_current_screenings_renders_without_error(): void
    {
        Movie::create(['title' => 'Vieux Film', 'slug' => 'vieux-film']);

        $this->get('/cinema/films/vieux-film')->assertOk()->assertSee('Aucune séance programmée actuellement.');
    }
}
