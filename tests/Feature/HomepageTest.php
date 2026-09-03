<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Category;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\Page;
use App\Models\Slider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie que la homepage se rend et affiche chaque section quand du
 * contenu existe — garde-fou pour les phases 6-10 (voir
 * TECHNICAL_DOCUMENTATION.md §12).
 */
class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_with_no_content(): void
    {
        $this->get('/')->assertOk()->assertSee('ToulouseWeb');
    }

    public function test_homepage_renders_all_sections_with_content(): void
    {
        Page::create(['key' => 'home', 'title' => 'Accueil', 'slug' => 'accueil']);

        $slider = Slider::create(['title' => 'Demo', 'image' => 'https://example.test/img.jpg', 'is_active' => true]);
        $slider->placements()->create(['page' => 'home']);

        $newsCategory = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        News::create([
            'category_id' => $newsCategory->id,
            'title' => 'Une actualité de test',
            'slug' => 'une-actualite-de-test',
            'body' => '<p>Test</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $area = Area::create(['name' => 'Salle de test', 'slug' => 'salle-de-test']);
        $eventCategory = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $event = Event::create([
            'area_id' => $area->id,
            'title' => 'Un événement de test',
            'slug' => 'un-evenement-de-test',
            'status' => 'published',
            'start_date' => now()->addDay(),
        ]);
        $event->categories()->attach($eventCategory);

        $movie = Movie::create(['title' => 'Un film de test', 'slug' => 'un-film-de-test']);
        $movie->screenings()->create([
            'cinema_id' => \App\Models\Cinema::create(['name' => 'Cinéma Test', 'slug' => 'cinema-test'])->id,
        ]);

        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        $listing = Listing::create([
            'title' => 'Un restaurant de test',
            'slug' => 'un-restaurant-de-test',
            'tier' => 'paid',
            'status' => 'published',
        ]);
        $listing->categories()->attach($category);

        $classifiedCategory = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        Classified::create([
            'category_id' => $classifiedCategory->id,
            'title' => 'Une annonce de test',
            'slug' => 'une-annonce-de-test',
            'description' => 'Test',
            'status' => 'published',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Une actualité de test')
            ->assertSee('Un événement de test')
            ->assertSee('Un film de test')
            ->assertSee('Un restaurant de test')
            ->assertSee('Une annonce de test')
            ->assertSee('Restaurants');
    }

    /**
     * Icône de catégorie définie dans l'admin (demande client) — voir
     * home.blade.php, section "Catégories populaires". Repli sur l'icône
     * générique par défaut si non renseignée (2e assertion).
     */
    public function test_homepage_category_uses_admin_icon_when_set(): void
    {
        Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'icon' => 'categories/fork.png']);
        Category::create(['name' => 'Sports', 'slug' => 'sports']);

        $response = $this->get('/')->assertOk();
        $response->assertSee('src="'.\Illuminate\Support\Facades\Storage::disk('public')->url('categories/fork.png').'"', false);
        // Sports (sans icône admin) garde l'icône SVG générique par défaut.
        $response->assertSee('stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 1015 0 7.5 7.5 0 00-15 0z"', false);
    }

    /**
     * Miniature actu de la home (demande client, 03/09/2026) : affiche la
     * date de début → fin de l'ÉVÉNEMENT décrit par l'article (pas la date
     * de publication) quand elle est renseignée. Repli sur la date de
     * publication pour une actu classique sans dates d'événement.
     */
    public function test_homepage_news_card_shows_event_date_range_when_set(): void
    {
        $news = News::create([
            'title' => 'Salon du jouet', 'slug' => 'salon-du-jouet', 'body' => '<p>x</p>', 'status' => 'published',
            'published_at' => now(), 'start_date' => now()->addDays(3), 'end_date' => now()->addDays(5),
        ]);

        // Recalculé via l'accesseur du modèle plutôt que reconstruit à la main,
        // pour ne pas dépliquer/désynchroniser le format exact (séparateur inclus).
        $this->get('/')->assertOk()->assertSee($news->fresh()->event_date_range);
    }

    /** Flèches précédent/suivant du slider (demande client) — voir components/site/hero-slider.blade.php. */
    public function test_slider_shows_chevron_arrows_only_with_multiple_slides(): void
    {
        $single = Slider::create(['title' => 'Seul slide', 'image' => 'https://example.test/img.jpg', 'is_active' => true]);
        $single->placements()->create(['page' => 'home']);

        $response = $this->get('/')->assertOk();
        $response->assertDontSee('Diapositive précédente');
        $response->assertDontSee('Diapositive suivante');

        $second = Slider::create(['title' => 'Second slide', 'image' => 'https://example.test/img2.jpg', 'is_active' => true]);
        $second->placements()->create(['page' => 'home']);

        $response = $this->get('/')->assertOk();
        $response->assertSee('Diapositive précédente');
        $response->assertSee('Diapositive suivante');
        $response->assertSee('#CC0000', false);
    }
}
