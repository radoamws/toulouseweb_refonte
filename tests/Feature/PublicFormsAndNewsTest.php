<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\ContactMessage;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Actualités publiques, dépôt d'annonce et formulaire de contact — vérifie
 * en particulier que le workflow de modération des annonces (brief §8) ne
 * peut pas être contourné depuis le frontend.
 */
class PublicFormsAndNewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_actualites_index_and_show_render(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Une actu de test', 'slug' => 'une-actu-de-test',
            'body' => '<p>Contenu</p>', 'status' => 'published', 'published_at' => now(),
        ]);

        $this->get('/actualites')->assertOk()->assertSee('Une actu de test');
        $this->get('/actualites/vie-locale')->assertOk()->assertSee('Une actu de test');
        $this->get('/actualites/une-actu-de-test')->assertOk()->assertSee('Contenu');
    }

    public function test_draft_news_is_not_accessible(): void
    {
        News::create(['title' => 'Brouillon', 'slug' => 'brouillon', 'body' => 'x', 'status' => 'draft']);

        $this->get('/actualites/brouillon')->assertNotFound();
    }

    public function test_classified_submission_always_starts_pending_and_is_not_publicly_visible(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $response = $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Citadine à vendre',
            'description' => 'Très bon état.',
            'contact_email' => 'vendeur@example.test',
            'website' => '', // honeypot vide = humain
        ]);

        $response->assertRedirect(route('annonces.index'));

        $classified = Classified::where('title', 'Citadine à vendre')->firstOrFail();
        $this->assertSame('pending', $classified->status);

        // Pas visible publiquement tant qu'elle n'est pas validée par l'admin.
        $this->get('/annonces/'.$classified->slug)->assertNotFound();
        $this->get('/annonces')->assertOk()->assertDontSee('Citadine à vendre');
    }

    public function test_classified_submission_cannot_inject_a_status_field(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Tentative de contournement',
            'description' => 'x',
            'contact_email' => 'a@example.test',
            'status' => 'published', // ne doit jamais être pris en compte
            'website' => '',
        ]);

        $classified = Classified::where('title', 'Tentative de contournement')->firstOrFail();
        $this->assertSame('pending', $classified->status);
    }

    public function test_classified_submission_rejected_when_honeypot_filled(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Spam bot',
            'description' => 'x',
            'contact_email' => 'a@example.test',
            'website' => 'http://spam.example', // honeypot rempli = bot
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('classifieds', ['title' => 'Spam bot']);
    }

    public function test_published_classified_is_publicly_visible(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Annonce publiée', 'slug' => 'annonce-publiee',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        $this->get('/annonces/'.$classified->slug)->assertOk()->assertSee('Annonce publiée');
    }

    /** Maillage interne (brief §13, SEO/GEO) — voir docblock de ClassifiedController::show(). */
    public function test_classified_show_lists_related_classifieds_in_the_same_category(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $other = ClassifiedCategory::create(['name' => 'Immobilier', 'slug' => 'immobilier']);

        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Citadine principale', 'slug' => 'citadine-principale',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);
        Classified::create([
            'category_id' => $category->id, 'title' => 'Autre voiture', 'slug' => 'autre-voiture',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);
        Classified::create([
            'category_id' => $other->id, 'title' => 'Un appartement', 'slug' => 'un-appartement',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        $response = $this->get('/annonces/'.$classified->slug)->assertOk();
        $response->assertSee('Autre voiture')->assertDontSee('Un appartement');
    }

    public function test_listing_submission_always_starts_pending_and_free_and_is_not_publicly_visible(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $response = $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'title' => 'Mon Petit Restaurant',
            'city' => 'Toulouse',
            'url_verification' => '', // honeypot vide = humain
        ]);

        $response->assertRedirect(route('annuaire.index'));

        $listing = Listing::where('title', 'Mon Petit Restaurant')->firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->assertSame('free', $listing->tier);
        $this->assertTrue($listing->categories->contains($category));

        // Pas visible publiquement tant qu'elle n'est pas validée par l'admin.
        $this->get('/annuaire/fiche/'.$listing->slug)->assertNotFound();
        $this->get('/annuaire')->assertOk()->assertDontSee('Mon Petit Restaurant');
    }

    public function test_listing_submission_cannot_inject_status_or_tier(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'title' => 'Tentative de contournement',
            'status' => 'published', // ne doit jamais être pris en compte
            'tier' => 'paid', // idem
            'url_verification' => '',
        ]);

        $listing = Listing::where('title', 'Tentative de contournement')->firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->assertSame('free', $listing->tier);
    }

    public function test_listing_submission_rejected_when_honeypot_filled(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'title' => 'Spam bot',
            'url_verification' => 'http://spam.example', // honeypot rempli = bot
        ])->assertSessionHasErrors('url_verification');

        $this->assertDatabaseMissing('listings', ['title' => 'Spam bot']);
    }

    public function test_contact_form_submission_is_stored(): void
    {
        $this->post('/contact', [
            'name' => 'Jean Test',
            'email' => 'jean@example.test',
            'message' => 'Un message de test.',
            'website' => '',
        ])->assertRedirect(route('contact.show'));

        $this->assertDatabaseHas(ContactMessage::class, ['email' => 'jean@example.test']);
    }

    public function test_contact_form_rejected_when_honeypot_filled(): void
    {
        $this->post('/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.test',
            'message' => 'spam',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('contact_messages', ['email' => 'bot@example.test']);
    }
}
