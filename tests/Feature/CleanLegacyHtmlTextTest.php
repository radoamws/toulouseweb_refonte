<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:clean-legacy-html` (demande client, 11/09/2026 — capture d'écran
 * d'une fiche annuaire affichant `<p>- D&eacute;pannage...<br />...</p>`
 * littéralement). Voir docblock de App\Console\Commands\Migration\CleanLegacyHtmlText.
 */
class CleanLegacyHtmlTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleans_listing_description_and_short_description(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => '2MSI', 'slug' => '2msi', 'tier' => 'free', 'status' => 'published',
            'description' => '<p>- D&eacute;pannage - R&eacute;paration<br />- Maintenance</p>',
            'short_description' => 'Vente &amp; r&eacute;paration<br />mat&eacute;riel',
        ]);
        $listing->categories()->attach($category->id);

        Artisan::call('content:clean-legacy-html');

        $listing->refresh();
        $this->assertSame("- Dépannage - Réparation\n- Maintenance", $listing->description);
        $this->assertSame("Vente & réparation\nmatériel", $listing->short_description);
    }

    public function test_cleans_event_description(): void
    {
        $area = \App\Models\Area::create(['name' => 'Un lieu']);
        $event = Event::create([
            'area_id' => $area->id, 'title' => 'Un événement', 'slug' => 'un-evenement',
            'description' => 'Le concert aura lieu &agrave; 20h<br />R&eacute;servation conseill&eacute;e.',
            'start_date' => now()->addWeek(), 'status' => 'published',
        ]);

        Artisan::call('content:clean-legacy-html');

        $event->refresh();
        $this->assertSame("Le concert aura lieu à 20h\nRéservation conseillée.", $event->description);
    }

    public function test_cleans_news_excerpt(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Une actu', 'slug' => 'une-actu',
            'excerpt' => 'Un r&eacute;sum&eacute; avec des &eacute;v&eacute;nements&hellip;',
            'body' => 'x', 'status' => 'published', 'published_at' => now(),
        ]);

        Artisan::call('content:clean-legacy-html');

        $news->refresh();
        $this->assertSame('Un résumé avec des événements…', $news->excerpt);
    }

    public function test_already_clean_text_is_left_untouched(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Déjà propre', 'slug' => 'deja-propre', 'tier' => 'free', 'status' => 'published',
            'description' => 'Un texte déjà en clair, sans balise ni entité.',
        ]);
        $listing->categories()->attach($category->id);

        Artisan::call('content:clean-legacy-html');

        $listing->refresh();
        $this->assertSame('Un texte déjà en clair, sans balise ni entité.', $listing->description);
    }

    /** listings.opening_hours (JSON, clé legacy_text) — audit SEO/GEO du 11/09/2026, TECHNICAL_DOCUMENTATION.md §30. */
    public function test_cleans_opening_hours_legacy_text_inside_the_json_column(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Parc de loisirs', 'slug' => 'parc-de-loisirs', 'tier' => 'free', 'status' => 'published',
            'opening_hours' => ['legacy_text' => 'Horaires :<br>lundi - samedi :<br>08:00&ndash;13:00'],
        ]);

        Artisan::call('content:clean-legacy-html');

        $listing->refresh();
        $this->assertStringNotContainsString('<br>', $listing->opening_hours['legacy_text']);
        $this->assertStringContainsString("Horaires :\nlundi - samedi :\n08:00–13:00", $listing->opening_hours['legacy_text']);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => '2MSI', 'slug' => '2msi', 'tier' => 'free', 'status' => 'published',
            'description' => '<p>- D&eacute;pannage</p>',
        ]);
        $listing->categories()->attach($category->id);

        Artisan::call('content:clean-legacy-html', ['--dry-run' => true]);

        $listing->refresh();
        $this->assertSame('<p>- D&eacute;pannage</p>', $listing->description);
    }

    /**
     * ⚠️ Garde-fou explicite (voir docblock de la commande) : cette
     * commande écrit via le query builder, JAMAIS Eloquent `->save()` —
     * sur ~10 800 lignes réelles en production, un `save()` en boucle
     * aurait déclenché les observers (purge Cloudflare, indexation Google,
     * régénération de sitemap) des milliers de fois pour une simple
     * correction de texte. Vérifié ici en activant la purge Cloudflare
     * (désactivée par défaut, voir phpunit.xml) : aucun appel HTTP sortant
     * ne doit être fait par cette commande.
     */
    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        Http::fake();

        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => '2MSI', 'slug' => '2msi', 'tier' => 'free', 'status' => 'published',
            'description' => '<p>- D&eacute;pannage</p>',
        ]);
        $listing->categories()->attach($category->id);

        // La création elle-même (Eloquent) déclenche bien l'observer — on
        // vide cette file avant de tester la commande, pour n'observer QUE
        // son propre comportement à elle.
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        Http::fake(); // réinitialise le journal des requêtes envoyées

        Artisan::call('content:clean-legacy-html');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();

        Http::assertNothingSent();
    }
}
