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
}
