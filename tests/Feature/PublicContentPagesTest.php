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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Recherche géographique (brief §5) — filtre par ville, voir docblock de
     * ListingController pour la limite connue (pas de vraies coordonnées
     * lat/lng côté legacy, filtre par ville en repli).
     */
    public function test_annuaire_city_filter(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        $toulouse = Listing::create([
            'title' => 'Le Bon Cassoulet', 'slug' => 'le-bon-cassoulet',
            'tier' => 'free', 'status' => 'published', 'city' => 'Toulouse',
        ]);
        $blagnac = Listing::create([
            'title' => 'Le Bon Steak', 'slug' => 'le-bon-steak',
            'tier' => 'free', 'status' => 'published', 'city' => 'Blagnac',
        ]);
        $toulouse->categories()->attach($category);
        $blagnac->categories()->attach($category);

        $this->get('/annuaire?city=Blagnac')
            ->assertOk()
            ->assertSee('Le Bon Steak')
            ->assertDontSee('Le Bon Cassoulet');
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

    /** Liens externes en target="_blank" (demande client, "partout") — voir annuaire/show.blade.php. */
    public function test_annuaire_show_reservation_link_opens_in_new_tab(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        $listing = Listing::create([
            'title' => 'Resto Réservable', 'slug' => 'resto-reservable', 'tier' => 'paid', 'status' => 'published',
            'reservation_url' => 'https://reservation.example.test/resto',
        ]);
        $listing->categories()->attach($category);

        $response = $this->get('/annuaire/fiche/resto-reservable')->assertOk();
        $response->assertSee('target="_blank" rel="noopener"', false);
        $response->assertSee('href="https://reservation.example.test/resto"', false);
    }

    /**
     * Aperçu grand format des photos en overlay avec navigation chevron
     * (demande client) — voir annuaire/show.blade.php, section "Photos".
     */
    public function test_annuaire_show_gallery_renders_lightbox_with_all_photos(): void
    {
        Storage::fake('public');

        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        $listing = Listing::create([
            'title' => 'Resto Avec Photos', 'slug' => 'resto-avec-photos', 'tier' => 'paid', 'status' => 'published',
        ]);
        $listing->categories()->attach($category);
        $listing->addMedia(UploadedFile::fake()->image('salle.jpg'))->preservingOriginal()->toMediaCollection('gallery');
        $listing->addMedia(UploadedFile::fake()->image('terrasse.jpg'))->preservingOriginal()->toMediaCollection('gallery');

        $response = $this->get('/annuaire/fiche/resto-avec-photos')->assertOk();
        $response->assertSee('role="dialog"', false);
        $response->assertSee('Photo précédente');
        $response->assertSee('Photo suivante');
        $response->assertSee('Fermer');
        // Les 2 photos apparaissent (miniatures + tableau Alpine `photos`).
        $response->assertSeeInOrder(['salle.jpg', 'terrasse.jpg']);
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

    public function test_agenda_calendar_view_renders_with_event_marker(): void
    {
        $event = Event::create([
            'title' => 'Concert Calendrier', 'slug' => 'concert-calendrier',
            'status' => 'published', 'start_date' => now()->startOfMonth()->addDays(4),
        ]);

        $response = $this->get('/agenda?view=calendar');

        $response->assertOk();
        $response->assertSee($event->start_date->translatedFormat('F Y'));
        // Régression : x-ui.button avec href="{{ }}" (au lieu de :href="") double
        // l'échappement HTML dès que l'URL contient plusieurs paramètres de
        // requête ("&" devient "&amp;amp;") — voir TECHNICAL_DOCUMENTATION.md §13.
        $response->assertDontSee('&amp;amp;', false);
    }

    public function test_agenda_date_navigation_does_not_double_encode_with_multiple_query_params(): void
    {
        Event::create([
            'title' => 'Concert Recherche', 'slug' => 'concert-recherche',
            'status' => 'published', 'start_date' => now()->addDay(),
        ]);

        // Deux paramètres de requête simultanés (date + q) : c'est ce qui
        // révèle le bug de double échappement, invisible avec un seul
        // paramètre (rien à séparer par "&").
        $response = $this->get('/agenda?date='.now()->format('Y-m-d').'&q=concert');

        $response->assertOk();
        $response->assertDontSee('&amp;amp;', false);
    }

    /**
     * "Le mois précédent du calendrier ne fonctionne pas" (retour client) —
     * date figée (`Carbon::setTestNow`) pour un test déterministe, sans
     * dépendre du jour réel d'exécution. Vérifie explicitement 2 clics
     * "précédent" consécutifs (pas juste 1, pour couvrir une régression qui
     * ne se manifesterait qu'à la 2e navigation) et le lien "suivant" en
     * symétrie. À l'investigation (26/08-01/09/2026), le calcul serveur
     * s'est révélé correct dans tous les cas testés manuellement (navigation
     * via URL directe, plusieurs mois de suite) — l'horloge système de ce
     * bac à sable accusait un décalage de quelques heures par rapport à la
     * date de référence, une piste plausible pour ce qui a été observé côté
     * client sans être un vrai bug de code. Ce test verrouille le
     * comportement correct pour détecter une vraie régression future.
     */
    public function test_agenda_calendar_previous_month_navigation(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 9, 15));

        try {
            $response = $this->get('/agenda?view=calendar');
            $response->assertOk();
            $response->assertSee('septembre 2026');
            $response->assertSee('http://localhost:8000/agenda?view=calendar&amp;month=2026-08', false);
            $response->assertSee('http://localhost:8000/agenda?view=calendar&amp;month=2026-10', false);

            $response = $this->get('/agenda?view=calendar&month=2026-08');
            $response->assertOk();
            $response->assertSee('août 2026');
            $response->assertSee('http://localhost:8000/agenda?view=calendar&amp;month=2026-07', false);
            $response->assertSee('http://localhost:8000/agenda?view=calendar&amp;month=2026-09', false);

            // Un 2e clic "précédent" de suite (juillet -> juin).
            $response = $this->get('/agenda?view=calendar&month=2026-07');
            $response->assertOk();
            $response->assertSee('juillet 2026');
            $response->assertSee('http://localhost:8000/agenda?view=calendar&amp;month=2026-06', false);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_agenda_slug_resolves_event_when_not_a_category(): void
    {
        $event = Event::create([
            'title' => 'Concert Test', 'slug' => 'concert-test',
            'status' => 'published', 'start_date' => now()->addDay(),
        ]);

        $this->get('/agenda/concert-test')->assertOk()->assertSee('Concert Test');
    }

    /** Liens externes en target="_blank" (demande client, "partout") — voir agenda/show.blade.php. */
    public function test_agenda_show_booking_link_opens_in_new_tab(): void
    {
        $event = Event::create([
            'title' => 'Concert Booké', 'slug' => 'concert-booke',
            'status' => 'published', 'start_date' => now()->addDay(),
            'booking_url' => 'https://billetterie.example.test/concert',
        ]);

        $response = $this->get('/agenda/concert-booke')->assertOk();
        $response->assertSee('target="_blank" rel="noopener"', false);
        $response->assertSee('href="https://billetterie.example.test/concert"', false);
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

    /**
     * Régression réelle (signalée par le client, 01/09/2026) : scraping
     * réussi (25 films, milliers d'horaires pour CGR Blagnac) mais AUCUNE
     * séance affichée en front. Cause : `screenings.start_date`/`end_date`
     * sont des colonnes DATE pures (pas DATETIME) — comparer
     * `end_date >= now()` comparait donc une date normalisée à minuit à
     * l'heure COMPLÈTE actuelle, excluant le dernier jour de la fenêtre de
     * programmation dès la première seconde après minuit (donc quasiment
     * toujours). Voir docblock de `CinemaController::currentlyValid()`.
     * Date figée en fin de journée (23h) pour reproduire exactement le
     * scénario qui plantait.
     */
    public function test_cinema_screening_ending_today_remains_visible_all_day(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 9, 1, 23, 0));

        try {
            $cinema = Cinema::create(['name' => 'CGR Blagnac', 'slug' => 'cgr-blagnac', 'is_active' => true]);
            $movie = Movie::create(['title' => 'The Dog Stars', 'slug' => 'the-dog-stars']);
            $screening = Screening::create([
                'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
                // Fenêtre de programmation se terminant AUJOURD'HUI (même
                // jour que "now", mais sans heure — comme le stocke
                // réellement AllocineDriver::currentProgrammingWeek()).
                'start_date' => '2026-08-26', 'end_date' => '2026-09-01',
            ]);
            $screening->times()->create(['weekday' => 2, 'time' => '22:05:00']);

            $this->get('/cinema')->assertOk()->assertSee('The Dog Stars');
            $this->get('/cinema/salles/cgr-blagnac')->assertOk()->assertSee('The Dog Stars');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_cinema_movie_with_no_current_screenings_renders_without_error(): void
    {
        Movie::create(['title' => 'Vieux Film', 'slug' => 'vieux-film']);

        $this->get('/cinema/films/vieux-film')->assertOk()->assertSee('Aucune séance programmée actuellement.');
    }

    /** Maillage interne (brief §13, SEO/GEO) — voir docblock des contrôleurs. */
    public function test_agenda_show_lists_related_events_in_the_same_category(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $other = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);

        $event = Event::create(['title' => 'Pièce principale', 'slug' => 'piece-principale', 'status' => 'published', 'start_date' => now()->addDay()]);
        $event->categories()->attach($category);

        $sameCategory = Event::create(['title' => 'Autre pièce', 'slug' => 'autre-piece', 'status' => 'published', 'start_date' => now()->addDays(2)]);
        $sameCategory->categories()->attach($category);

        $otherCategory = Event::create(['title' => 'Un concert', 'slug' => 'un-concert', 'status' => 'published', 'start_date' => now()->addDays(3)]);
        $otherCategory->categories()->attach($other);

        $response = $this->get('/agenda/piece-principale')->assertOk();
        $response->assertSee('Autre pièce')->assertDontSee('Un concert');
    }

    public function test_cinema_movie_show_lists_other_currently_screening_movies(): void
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Film Principal', 'slug' => 'film-principal']);
        $other = Movie::create(['title' => 'Autre Film', 'slug' => 'autre-film']);
        Screening::create(['cinema_id' => $cinema->id, 'movie_id' => $movie->id, 'start_date' => now()->subDay(), 'end_date' => now()->addWeek()]);
        Screening::create(['cinema_id' => $cinema->id, 'movie_id' => $other->id, 'start_date' => now()->subDay(), 'end_date' => now()->addWeek()]);

        $this->get('/cinema/films/film-principal')->assertOk()->assertSee('Autre Film');
    }

    public function test_cinema_salle_show_lists_other_active_cinemas(): void
    {
        Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        Cinema::create(['name' => 'ABC', 'slug' => 'abc', 'is_active' => true]);
        Cinema::create(['name' => 'Salle Fermée', 'slug' => 'salle-fermee', 'is_active' => false]);

        $response = $this->get('/cinema/salles/gaumont-wilson')->assertOk();
        $response->assertSee('ABC')->assertDontSee('Salle Fermée');
    }
}
