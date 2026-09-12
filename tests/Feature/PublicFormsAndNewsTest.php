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

    /**
     * Tri par défaut de /actualites (demande client, 12/09/2026) :
     * publication la plus récente d'abord. Voir NewsController::renderIndex().
     */
    public function test_actualites_index_sorts_by_most_recent_publication_by_default(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-tri']);
        News::create(['category_id' => $category->id, 'title' => 'Plus ancienne', 'slug' => 'plus-ancienne', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDays(5)]);
        News::create(['category_id' => $category->id, 'title' => 'Plus recente', 'slug' => 'plus-recente', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

        $response = $this->get('/actualites')->assertOk();
        $response->assertSeeInOrder(['Plus recente', 'Plus ancienne']);
    }

    /** Choix de tri explicite : publication la plus ancienne d'abord. */
    public function test_actualites_index_can_sort_by_oldest_publication(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-tri2']);
        News::create(['category_id' => $category->id, 'title' => 'Plus ancienne', 'slug' => 'plus-ancienne-2', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDays(5)]);
        News::create(['category_id' => $category->id, 'title' => 'Plus recente', 'slug' => 'plus-recente-2', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

        $response = $this->get('/actualites?sort=published_asc')->assertOk();
        $response->assertSeeInOrder(['Plus ancienne', 'Plus recente']);
    }

    /**
     * Tri par date de l'événement (croissante/décroissante) — les articles
     * SANS date d'événement (la majorité, simples actus) restent toujours
     * relégués en fin de liste, quel que soit le sens choisi.
     */
    public function test_actualites_index_can_sort_by_event_date_pushing_articles_without_one_to_the_end(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-tri3']);
        News::create(['category_id' => $category->id, 'title' => 'Sans date événement', 'slug' => 'sans-date-evenement', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);
        News::create(['category_id' => $category->id, 'title' => 'Événement proche', 'slug' => 'evenement-proche', 'body' => 'x', 'status' => 'published', 'published_at' => now(), 'start_date' => now()->addDays(2)]);
        News::create(['category_id' => $category->id, 'title' => 'Événement lointain', 'slug' => 'evenement-lointain', 'body' => 'x', 'status' => 'published', 'published_at' => now(), 'start_date' => now()->addDays(20)]);

        $asc = $this->get('/actualites?sort=event_asc')->assertOk();
        $asc->assertSeeInOrder(['Événement proche', 'Événement lointain', 'Sans date événement']);

        $desc = $this->get('/actualites?sort=event_desc')->assertOk();
        $desc->assertSeeInOrder(['Événement lointain', 'Événement proche', 'Sans date événement']);
    }

    /** Une valeur de tri inventée retombe silencieusement sur le tri par défaut, pas une erreur. */
    public function test_actualites_index_ignores_an_invalid_sort_value(): void
    {
        NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-tri4']);

        $this->get('/actualites?sort=n-importe-quoi')->assertOk();
    }

    public function test_draft_news_is_not_accessible(): void
    {
        News::create(['title' => 'Brouillon', 'slug' => 'brouillon', 'body' => 'x', 'status' => 'draft']);

        $this->get('/actualites/brouillon')->assertNotFound();
    }

    /**
     * Demande client (03/09/2026) : une actualité-événement dont la date de
     * fin est dépassée ne doit plus s'afficher sur le site public, même
     * publiée. Comparaison en date pure (voir News::scopePublished()).
     */
    public function test_news_with_end_date_in_the_past_is_no_longer_publicly_visible(): void
    {
        News::create([
            'title' => 'Événement terminé', 'slug' => 'evenement-termine', 'body' => 'x', 'status' => 'published',
            'start_date' => now()->subWeek(), 'end_date' => now()->subDay(),
        ]);

        $this->get('/actualites/evenement-termine')->assertNotFound();
        $this->get('/actualites')->assertOk()->assertDontSee('Événement terminé');
    }

    /** end_date == aujourd'hui (juste les dates, pas l'heure) doit rester visible toute la journée. */
    public function test_news_ending_today_remains_visible_all_day(): void
    {
        News::create([
            'title' => 'Événement du jour', 'slug' => 'evenement-du-jour', 'body' => 'x', 'status' => 'published',
            'start_date' => now()->subDay(), 'end_date' => now(),
        ]);

        $this->get('/actualites/evenement-du-jour')->assertOk()->assertSee('Événement du jour');
    }

    /** Un article sans start_date/end_date (actu classique, sans événement) reste visible normalement. */
    public function test_news_without_event_dates_remains_publicly_visible(): void
    {
        News::create([
            'title' => 'Actu classique', 'slug' => 'actu-classique', 'body' => 'x',
            'status' => 'published', 'published_at' => now(),
        ]);

        $this->get('/actualites/actu-classique')->assertOk()->assertSee('Actu classique');
    }

    /**
     * Champs "informations pratiques" (demande client, 03/09/2026) : chacun
     * ne s'affiche que s'il a une valeur, avec target="_blank" pour le site
     * web et la vidéo YouTube intégrée en iframe.
     */
    public function test_news_show_renders_event_fields_only_when_present(): void
    {
        News::create([
            'title' => 'Brocante du quartier', 'slug' => 'brocante-du-quartier', 'body' => '<p>x</p>', 'status' => 'published',
            'start_date' => now()->addWeek(), 'end_date' => now()->addWeek()->addDay(),
            'schedule' => 'Tous les jours de 9h à 18h', 'address' => 'Place du Capitole, Toulouse',
            'price' => 'Entrée gratuite', 'phone' => '0561000000', 'email' => 'contact@brocante.test',
            'website' => 'https://brocante.example.test', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response = $this->get('/actualites/brocante-du-quartier')->assertOk();
        $response->assertSee('Tous les jours de 9h à 18h');
        $response->assertSee('Place du Capitole, Toulouse');
        $response->assertSee('Entrée gratuite');
        $response->assertSee('href="tel:0561000000"', false);
        $response->assertSee('href="mailto:contact@brocante.test"', false);
        $response->assertSee('href="https://brocante.example.test"', false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('src="https://www.youtube.com/embed/dQw4w9WgXcQ"', false);

        $minimal = News::create([
            'title' => 'Actu sans infos pratiques', 'slug' => 'actu-sans-infos', 'body' => '<p>x</p>', 'status' => 'published',
        ]);
        $response = $this->get('/actualites/actu-sans-infos')->assertOk();
        $response->assertDontSee('Tarif');
        $response->assertDontSee('<iframe', false);
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

    public function test_listing_submission_always_starts_pending_and_is_not_publicly_visible(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        // Depuis le 09/09/2026 (demande client), `tier` est un choix explicite
        // du visiteur (gratuit/payant) — voir TECHNICAL_DOCUMENTATION.md §28 —
        // donc toujours envoyé désormais, plus de valeur par défaut implicite.
        $response = $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'tier' => 'free',
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

    /**
     * `tier` est désormais un choix légitime du visiteur (voir test
     * ci-dessus et TECHNICAL_DOCUMENTATION.md §28) — mais `status`, lui,
     * reste TOUJOURS non-injectable, quelle que soit la formule choisie :
     * jamais de publication automatique, même pour une demande payante
     * (contrairement à un bug du formulaire legacy équivalent, documenté
     * dans TECHNICAL_DOCUMENTATION.md §28).
     */
    public function test_listing_submission_cannot_inject_status_even_with_paid_tier(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'tier' => 'paid', // choix légitime, doit être respecté
            'title' => 'Tentative de contournement',
            'status' => 'published', // ne doit jamais être pris en compte
            'url_verification' => '',
        ]);

        $listing = Listing::where('title', 'Tentative de contournement')->firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->assertSame('paid', $listing->tier);
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
