<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demande client, 01/10/2026 : image de marque ToulouseWeb par défaut pour
 * tous les encadrés ("cards") de toutes les entités qui n'ont pas de vraie
 * image — y compris les fiches annuaire GRATUITES, qui n'en ont jamais par
 * conception (brief §5). Fichier déplacé vers `public/branding/default-card-image.png`
 * (même convention que `toulouseweb-icon.png`/`toulouseweb-logo.png`).
 *
 * Choix explicite du client (clarifié par question) : traitement CÔTÉ FRONT
 * uniquement (`x-ui.entity-image`, voir son docblock) — pas de backfill en
 * base, pour garder les colonnes `image`/collections média honnêtes (une
 * fiche sans vraie photo reste identifiable comme telle en admin).
 */
class DefaultEntityImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_listing_without_a_logo_shows_the_default_branding_image(): void
    {
        $listing = Listing::create([
            'title' => 'Boulangerie du quartier', 'slug' => 'boulangerie-du-quartier-default-img',
            'tier' => 'free', 'status' => 'published',
        ]);

        $response = $this->get('/annuaire/fiche/'.$listing->slug)->assertOk();
        $response->assertSee('branding/default-card-image.png');
    }

    public function test_listing_index_card_shows_the_default_branding_image_for_listings_without_a_logo(): void
    {
        $category = Category::create(['name' => 'Commerces', 'slug' => 'commerces-default-img', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Boulangerie du quartier', 'slug' => 'boulangerie-liste-default-img',
            'tier' => 'free', 'status' => 'published',
        ]);
        $listing->categories()->attach($category);

        $this->get('/annuaire')->assertOk()->assertSee('branding/default-card-image.png');
    }

    public function test_classified_without_a_photo_shows_the_default_branding_image(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Divers', 'slug' => 'divers-default-img']);
        $classified = Classified::create([
            'title' => 'Vélo à vendre', 'slug' => 'velo-a-vendre-default-img',
            'category_id' => $category->id, 'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        $response = $this->get('/annonces/'.$classified->slug)->assertOk();
        $response->assertSee('branding/default-card-image.png');
    }

    public function test_news_article_without_an_image_shows_the_default_branding_image(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-default-img']);
        $news = News::create([
            'title' => 'Une brocante ce week-end', 'slug' => 'brocante-default-img',
            'category_id' => $category->id, 'body' => 'x', 'status' => 'published', 'published_at' => now(),
        ]);

        $response = $this->get('/actualites/'.$news->slug)->assertOk();
        $response->assertSee('branding/default-card-image.png');
    }

    public function test_movie_without_a_poster_shows_the_default_branding_image(): void
    {
        $movie = Movie::create(['title' => 'Un film sans affiche', 'slug' => 'film-sans-affiche-default-img']);

        $response = $this->get('/cinema/films/'.$movie->slug)->assertOk();
        $response->assertSee('branding/default-card-image.png');
    }

    /** Une vraie image reste toujours prioritaire sur le repli par défaut. */
    public function test_entity_with_a_real_image_does_not_show_the_default_branding_image(): void
    {
        $movie = Movie::create(['title' => 'Un film avec affiche', 'slug' => 'film-avec-affiche-default-img', 'poster' => 'https://example.test/poster.jpg']);

        $response = $this->get('/cinema/films/'.$movie->slug)->assertOk();
        $response->assertDontSee('branding/default-card-image.png');
        $response->assertSee('https://example.test/poster.jpg', false);
    }
}
