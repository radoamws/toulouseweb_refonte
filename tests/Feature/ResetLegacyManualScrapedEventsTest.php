<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:reset-legacy-manual-scraped-events` — voir docblock de la
 * commande (demande client, 25/09/2026 : des exemples concrets d'adresse/date
 * fausses se sont révélés être des lignes `source='manual'` dont la
 * booking_url pointe vers un site activement scrapé — d'anciennes données de
 * scraping migrées, mal étiquetées, pas de vraies saisies à la main).
 */
class ResetLegacyManualScrapedEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_deletes_manual_events_pointing_to_a_scraped_domain(): void
    {
        $area = Area::create(['name' => "Le Vent des Signes", 'slug' => 'levent-legacy-manual']);

        $legacyScraped = Event::create([
            'title' => 'Soudain, une île (création)', 'slug' => 'soudain-une-ile-creation-legacy',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'booking_url' => 'https://www.leventdessignes.fr/class/soudain-une-ile_creation/',
            'start_date' => '2026-01-05 00:00:00', 'end_date' => '2027-01-10 00:00:00',
        ]);

        Artisan::call('content:reset-legacy-manual-scraped-events');

        $this->assertDatabaseMissing('events', ['id' => $legacyScraped->id]);
    }

    public function test_does_not_touch_manual_events_without_a_recognized_scraped_domain(): void
    {
        $area = Area::create(['name' => 'Association locale', 'slug' => 'assoc-legacy-manual']);

        $genuineManual = Event::create([
            'title' => 'Vide-grenier associatif', 'slug' => 'vide-grenier-associatif-manual',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'booking_url' => null, 'start_date' => '2026-10-01 00:00:00',
        ]);

        Artisan::call('content:reset-legacy-manual-scraped-events');

        $this->assertDatabaseHas('events', ['id' => $genuineManual->id, 'deleted_at' => null]);
    }

    public function test_does_not_touch_scraped_or_user_submitted_events(): void
    {
        $area = Area::create(['name' => "L'Escale", 'slug' => 'lescale-legacy-manual']);

        $scraped = Event::create([
            'title' => 'Spectacle scrapé récent', 'slug' => 'spectacle-scrape-recent-legacy',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'booking_url' => 'https://www.ardei-soft.com/tournefeuille/spectacle.html?spectacle=x',
            'external_ref' => 'x', 'start_date' => '2026-10-01 00:00:00',
        ]);
        $userSubmitted = Event::create([
            'title' => 'Événement visiteur', 'slug' => 'evenement-visiteur-legacy',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'user_submitted',
            'booking_url' => 'https://www.leventdessignes.fr/class/quelque-chose/',
            'start_date' => '2026-10-01 00:00:00',
        ]);

        Artisan::call('content:reset-legacy-manual-scraped-events');

        $this->assertDatabaseHas('events', ['id' => $scraped->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('events', ['id' => $userSubmitted->id, 'deleted_at' => null]);
    }

    public function test_deletion_is_permanent_not_a_soft_delete(): void
    {
        $area = Area::create(['name' => "Toulouse Métropole", 'slug' => 'toulouse-metropole-legacy-manual']);

        $legacyScraped = Event::create([
            'title' => 'Exposition Air France', 'slug' => 'exposition-air-france-legacy',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'booking_url' => 'https://openagenda.com/toulouse-metropole/events/exposition-air-france',
            'start_date' => '2026-10-01 00:00:00',
        ]);

        Artisan::call('content:reset-legacy-manual-scraped-events');

        $this->assertSame(0, Event::withTrashed()->where('id', $legacyScraped->id)->count());
    }

    public function test_dry_run_reports_but_deletes_nothing(): void
    {
        $area = Area::create(['name' => "Le Vent des Signes", 'slug' => 'levent-legacy-manual-dry']);

        $legacyScraped = Event::create([
            'title' => 'Soudain, une île (création)', 'slug' => 'soudain-une-ile-creation-dry',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'booking_url' => 'https://www.leventdessignes.fr/class/soudain-une-ile_creation/',
            'start_date' => '2026-01-05 00:00:00',
        ]);

        Artisan::call('content:reset-legacy-manual-scraped-events', ['--dry-run' => true]);

        $this->assertDatabaseHas('events', ['id' => $legacyScraped->id, 'deleted_at' => null]);
    }

    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        config(['services.google_indexing.enabled' => true, 'services.google_indexing.credentials_json_base64' => base64_encode('{}')]);

        $area = Area::create(['name' => "Le Vent des Signes", 'slug' => 'levent-legacy-manual-obs']);
        Event::create([
            'title' => 'Soudain, une île (création)', 'slug' => 'soudain-une-ile-creation-obs',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'booking_url' => 'https://www.leventdessignes.fr/class/soudain-une-ile_creation/',
            'start_date' => '2026-01-05 00:00:00',
        ]);

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();
        Http::fake();

        Artisan::call('content:reset-legacy-manual-scraped-events');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();

        Http::assertNothingSent();
    }
}
