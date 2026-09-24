<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:reset-scraped-agenda-events` — voir docblock de la commande
 * (demande client, 24/09/2026 : repartir d'une base propre pour l'agenda,
 * trop de doublons/incohérences de date accumulés malgré les correctifs
 * précédents). Portée strictement limitée à `source = 'scraped'`.
 */
class ResetScrapedAgendaEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_deletes_only_scraped_events(): void
    {
        $area = Area::create(['name' => "L'Escale", 'slug' => 'lescale-reset']);

        $scraped = Event::create([
            'title' => 'Spectacle scrapé', 'slug' => 'spectacle-scrape',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'spectacle-scrape', 'start_date' => '2026-09-26 10:00:00',
        ]);
        $manual = Event::create([
            'title' => 'Événement saisi à la main', 'slug' => 'evenement-manuel',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'start_date' => '2026-09-26 10:00:00',
        ]);
        $userSubmitted = Event::create([
            'title' => 'Événement soumis par un visiteur', 'slug' => 'evenement-visiteur',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'user_submitted',
            'start_date' => '2026-09-26 10:00:00',
        ]);

        Artisan::call('content:reset-scraped-agenda-events');

        $this->assertDatabaseMissing('events', ['id' => $scraped->id]);
        $this->assertDatabaseHas('events', ['id' => $manual->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('events', ['id' => $userSubmitted->id, 'deleted_at' => null]);
    }

    public function test_deletion_is_permanent_not_a_soft_delete(): void
    {
        // Choix explicite du client (24/09/2026) : force delete plutôt que
        // soft delete, pour libérer le slug avant le relance du scraping —
        // voir docblock de la commande (contrainte unique sur `events.slug`
        // + otherRecordExistsWithSlug() qui fait un withTrashed()).
        $area = Area::create(['name' => "L'Escale", 'slug' => 'lescale-reset-permanent']);

        $scraped = Event::create([
            'title' => 'Spectacle scrapé', 'slug' => 'spectacle-scrape-permanent',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'spectacle-scrape-permanent', 'start_date' => '2026-09-26 10:00:00',
        ]);

        Artisan::call('content:reset-scraped-agenda-events');

        $this->assertDatabaseMissing('events', ['id' => $scraped->id]);
        $this->assertSame(0, Event::withTrashed()->where('id', $scraped->id)->count());
    }

    public function test_dry_run_reports_but_deletes_nothing(): void
    {
        $area = Area::create(['name' => "L'Escale", 'slug' => 'lescale-reset-dry']);

        $scraped = Event::create([
            'title' => 'Spectacle scrapé', 'slug' => 'spectacle-scrape-dry',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'spectacle-scrape-dry', 'start_date' => '2026-09-26 10:00:00',
        ]);

        Artisan::call('content:reset-scraped-agenda-events', ['--dry-run' => true]);

        $this->assertDatabaseHas('events', ['id' => $scraped->id, 'deleted_at' => null]);
    }

    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        config(['services.google_indexing.enabled' => true, 'services.google_indexing.credentials_json_base64' => base64_encode('{}')]);

        $area = Area::create(['name' => "L'Escale", 'slug' => 'lescale-reset-observers']);
        Event::create([
            'title' => 'Spectacle scrapé', 'slug' => 'spectacle-scrape-obs',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'spectacle-scrape-obs', 'start_date' => '2026-09-26 10:00:00',
        ]);

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();
        Http::fake();

        Artisan::call('content:reset-scraped-agenda-events');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();

        Http::assertNothingSent();
    }
}
