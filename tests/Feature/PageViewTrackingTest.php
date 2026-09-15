<?php

namespace Tests\Feature;

use App\Models\Cinema;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\PageView;
use App\Models\Screening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vues de page (demande client, 15/09/2026 : "les pages visitées doivent
 * être dans les stats aussi car ce ne sera pas forcément un clic par lien
 * interne mais un résultat de recherche de google [...], donc aucun clic
 * sur des liens internes"). Enregistrées côté SERVEUR (App\Models\PageView,
 * App\Services\Stats\PageViewService) — contrairement à App\Models\ClickEvent,
 * qui dépend d'un clic JS suivi — pour capter aussi les visites directes.
 * Voir TECHNICAL_DOCUMENTATION.md §44.
 */
class PageViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_visit_is_recorded_without_an_entity(): void
    {
        $this->get('/')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => null, 'entity_id' => null, 'path' => '/']);
    }

    public function test_listing_show_records_a_page_view_tied_to_the_listing(): void
    {
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published']);

        $this->get('/annuaire/fiche/le-bistrot')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => 'listing', 'entity_id' => $listing->id]);
    }

    public function test_news_show_records_a_page_view_tied_to_the_article(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-pv']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Une actu vue', 'slug' => 'une-actu-vue',
            'body' => 'x', 'status' => 'published',
        ]);

        $this->get('/actualites/une-actu-vue')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => 'news', 'entity_id' => $news->id]);
    }

    public function test_agenda_show_records_a_page_view_tied_to_the_event(): void
    {
        $event = Event::create(['title' => 'Concert vu', 'slug' => 'concert-vu', 'status' => 'published', 'start_date' => now()->addWeek()]);

        $this->get('/agenda/concert-vu')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => 'event', 'entity_id' => $event->id]);
    }

    public function test_cinema_movie_and_salle_pages_record_page_views(): void
    {
        $cinema = Cinema::create(['name' => 'Salle Vue', 'slug' => 'salle-vue', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film Vu', 'slug' => 'film-vu']);
        Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subDay(), 'end_date' => now()->addWeek(),
        ]);

        $this->get('/cinema/films/film-vu')->assertOk();
        $this->get('/cinema/salles/salle-vue')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => 'movie', 'entity_id' => $movie->id]);
        $this->assertDatabaseHas('page_views', ['entity_type' => 'cinema', 'entity_id' => $cinema->id]);
    }

    public function test_classified_show_records_a_page_view_tied_to_the_ad(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures-pv']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Annonce vue', 'slug' => 'annonce-vue',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        $this->get('/annonces/annonce-vue')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => 'classified', 'entity_id' => $classified->id]);
    }

    public function test_event_category_page_records_a_page_view_tied_to_the_category(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre-pv']);

        $this->get('/agenda/theatre-pv')->assertOk();

        $this->assertDatabaseHas('page_views', ['entity_type' => 'event_category', 'entity_id' => $category->id]);
    }

    /** Un souci d'enregistrement des vues ne doit jamais casser la page réelle — voir Controller::recordPageView(). */
    public function test_page_still_renders_even_if_recording_the_view_fails(): void
    {
        \Illuminate\Support\Facades\Schema::drop('page_views');

        $this->get('/')->assertOk()->assertSee('ToulouseWeb');
    }
}
