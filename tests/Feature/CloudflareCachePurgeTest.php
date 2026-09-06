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
use App\Models\Page;
use App\Models\Slider;
use App\Services\Cache\CloudflareCachePurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Purge du cache Cloudflare à chaque ajout/modif/suppression de contenu
 * (demande client, TECHNICAL_DOCUMENTATION.md §17) — réécrit
 * `SharedController::purgeEntityCache()` du legacy, qui n'était réellement
 * câblé que sur 3 entités (voir docblock de CloudflareCachePurger).
 *
 * `config(['services.cloudflare...'])` active la purge UNIQUEMENT dans les
 * tests qui en ont besoin — jamais par défaut (voir phpunit.xml), pour ne
 * jamais risquer un vrai appel HTTP sortant pendant la suite de tests.
 */
class CloudflareCachePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function enablePurge(): void
    {
        config([
            'services.cloudflare.enabled' => true,
            'services.cloudflare.zone_id' => 'test-zone',
            'services.cloudflare.api_token' => 'test-token',
        ]);
    }

    protected function fakeCloudflareOk(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);
    }

    public function test_purge_is_disabled_by_default_even_with_zone_and_token_configured(): void
    {
        // `enabled` reste la double sécurité : même zone_id/api_token présents,
        // rien ne part tant que CLOUDFLARE_CACHE_PURGE_ENABLED n'est pas vrai.
        config(['services.cloudflare.zone_id' => 'test-zone', 'services.cloudflare.api_token' => 'test-token']);
        $this->fakeCloudflareOk();

        News::create(['title' => 'Actu', 'slug' => 'actu', 'body' => 'x', 'status' => 'published']);
        app(CloudflareCachePurger::class)->flush();

        Http::assertNothingSent();
    }

    public function test_purge_is_skipped_gracefully_when_enabled_but_not_configured(): void
    {
        config(['services.cloudflare.enabled' => true]); // zone_id/api_token restent vides
        $this->fakeCloudflareOk();

        News::create(['title' => 'Actu', 'slug' => 'actu', 'body' => 'x', 'status' => 'published']);
        app(CloudflareCachePurger::class)->flush();

        Http::assertNothingSent();
    }

    public function test_news_save_queues_home_index_and_category_urls(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        News::create([
            'category_id' => $category->id, 'title' => 'Actu', 'slug' => 'une-actu',
            'body' => 'x', 'status' => 'published',
        ]);
        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(function ($request) {
            $files = $request->data()['files'] ?? [];

            return str_contains($request->url(), 'purge_cache')
                && in_array(url('/'), $files, true)
                && in_array(route('actualites.index'), $files, true)
                && in_array(route('actualites.bySlug', 'une-actu'), $files, true)
                && in_array(route('actualites.bySlug', 'vie-locale'), $files, true);
        });
    }

    /** Un changement de slug purge AUSSI l'ancienne URL (voir docblock de CloudflarePurgeObserver). */
    public function test_slug_change_purges_both_old_and_new_url(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $news = News::create(['title' => 'Titre initial', 'slug' => 'titre-initial', 'body' => 'x', 'status' => 'published']);
        // Flush du "create" pour ne garder que les URLs du "update" ci-dessous.
        app(CloudflareCachePurger::class)->flush();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);

        $news->update(['slug' => 'titre-modifie']);
        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(function ($request) {
            $files = $request->data()['files'] ?? [];

            return in_array(route('actualites.bySlug', 'titre-initial'), $files, true)
                && in_array(route('actualites.bySlug', 'titre-modifie'), $files, true);
        });
    }

    /** Une simple création ne doit PAS tenter de purger une "ancienne" URL inexistante (pas de slug null dans route()). */
    public function test_creating_a_record_does_not_attempt_to_purge_a_previous_slug(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        News::create(['title' => 'Nouvelle actu', 'slug' => 'nouvelle-actu', 'body' => 'x', 'status' => 'published']);

        // Ne doit pas lever d'exception (route() avec un paramètre null) et ne doit
        // envoyer qu'UNE seule requête de purge (pas une pour l'état "avant").
        app(CloudflareCachePurger::class)->flush();
        Http::assertSentCount(1);
    }

    public function test_deleting_a_record_queues_its_urls(): void
    {
        $this->enablePurge();

        $news = News::create(['title' => 'À supprimer', 'slug' => 'a-supprimer', 'body' => 'x', 'status' => 'published']);
        app(CloudflareCachePurger::class)->flush();

        $this->fakeCloudflareOk();
        $news->delete(); // SoftDeletes : "deleted" se déclenche bien
        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(fn ($request) => in_array(route('actualites.bySlug', 'a-supprimer'), $request->data()['files'] ?? [], true));
    }

    public function test_event_purges_all_its_categories(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $theatre = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $concerts = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);
        $event = Event::create(['title' => 'Un événement', 'slug' => 'un-evenement', 'status' => 'published', 'start_date' => now()->addDay()]);
        $event->categories()->attach([$theatre->id, $concerts->id]);
        $event->touch(); // déclenche "saved" après l'attachement des catégories

        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(function ($request) {
            $files = $request->data()['files'] ?? [];

            return in_array(route('agenda.bySlug', 'un-evenement'), $files, true)
                && in_array(route('agenda.bySlug', 'theatre'), $files, true)
                && in_array(route('agenda.bySlug', 'concerts'), $files, true);
        });
    }

    public function test_listing_purges_its_categories_and_home(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $category = \App\Models\Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create(['title' => 'Un resto', 'slug' => 'un-resto', 'tier' => 'paid', 'status' => 'published']);
        $listing->categories()->attach($category->id);
        $listing->touch();

        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(function ($request) {
            $files = $request->data()['files'] ?? [];

            return in_array(url('/'), $files, true)
                && in_array(route('annuaire.show', 'un-resto'), $files, true)
                && in_array(route('annuaire.category', 'restaurants'), $files, true);
        });
    }

    public function test_classified_purges_its_category(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        Classified::create([
            'category_id' => $category->id, 'title' => 'Annonce', 'slug' => 'annonce',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(fn ($request) => in_array(route('annonces.bySlug', 'voitures'), $request->data()['files'] ?? [], true));
    }

    public function test_movie_and_cinema_purge_their_own_pages(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        Movie::create(['title' => 'Un film', 'slug' => 'un-film']);
        Cinema::create(['name' => 'Un cinéma', 'slug' => 'un-cinema']);

        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(function ($request) {
            $files = $request->data()['files'] ?? [];

            return in_array(route('cinema.movie', 'un-film'), $files, true)
                && in_array(route('cinema.salle', 'un-cinema'), $files, true);
        });
    }

    public function test_slider_purges_urls_for_each_placement_page(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $slider = Slider::create(['title' => 'Bannière', 'image' => 'https://example.test/img.jpg', 'is_active' => true]);
        $slider->placements()->create(['page' => 'home']);
        $slider->placements()->create(['page' => 'cinema']);
        $slider->touch();

        app(CloudflareCachePurger::class)->flush();

        Http::assertSent(function ($request) {
            $files = $request->data()['files'] ?? [];

            return in_array(url('/'), $files, true) && in_array(route('cinema.index'), $files, true);
        });
    }

    public function test_page_only_purges_known_keys(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        Page::create(['key' => 'home', 'title' => 'Accueil', 'slug' => 'accueil']);
        app(CloudflareCachePurger::class)->flush();
        Http::assertSent(fn ($request) => in_array(url('/'), $request->data()['files'] ?? [], true));

        $this->fakeCloudflareOk();
        Page::create(['key' => 'seo-menu-un-truc-inconnu', 'title' => 'Divers', 'slug' => 'divers']);
        app(CloudflareCachePurger::class)->flush();
        // Clé inconnue : aucune URL cible, donc rien à envoyer.
        Http::assertNothingSent();
    }

    /** Plus de 30 URLs à purger (limite de l'API Cloudflare par appel) : envoyées en plusieurs lots. */
    public function test_more_than_thirty_urls_are_sent_in_chunks(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $urls = array_map(fn ($i) => "https://example.test/page-{$i}", range(1, 45));
        app(CloudflareCachePurger::class)->queue($urls);
        app(CloudflareCachePurger::class)->flush();

        Http::assertSentCount(2);
    }

    /**
     * Preuve de bout en bout que `app()->terminating()` (voir AppServiceProvider)
     * déclenche bien le flush automatiquement après une VRAIE requête HTTP —
     * sans appel manuel à ->flush(), contrairement aux tests ci-dessus (qui
     * passent par Livewire/Eloquent direct, hors du cycle complet
     * requête → kernel->terminate()).
     */
    public function test_public_listing_submission_triggers_purge_automatically_via_terminating(): void
    {
        $this->enablePurge();
        $this->fakeCloudflareOk();

        $category = \App\Models\Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'title' => 'Mon Petit Restaurant',
            'city' => 'Toulouse',
            'url_verification' => '',
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'purge_cache'));
    }
}
