<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:dedupe-agenda-manual-entries` (demande client, 19/09/2026 : "il
 * y a des doublons [...] à corriger" — voir docblock de la commande et
 * TECHNICAL_DOCUMENTATION.md §47).
 */
class DedupeManualAgendaEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_entry_with_a_scraped_twin_on_the_same_day_is_deleted(): void
    {
        $area = Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou-dedupe']);

        $manual = Event::create([
            'title' => 'Le Bijou Comédie Club', 'slug' => 'le-bijou-comedie-club-manual',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'start_date' => '2026-10-13 00:00:00',
        ]);
        $scraped = Event::create([
            'title' => 'Le Bijou Comédie Club', 'slug' => 'le-bijou-comedie-club-scraped',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => '2473-Le-Bijou-Comedie-Club', 'start_date' => '2026-10-13 19:00:00',
        ]);

        Artisan::call('content:dedupe-agenda-manual-entries');

        $this->assertSoftDeleted($manual);
        $this->assertNotSoftDeleted($scraped);
    }

    /** Un événement manuel SANS jumeau scrapé (lieu sans scraper actif) est laissé intact, quelle que soit sa date. */
    public function test_manual_entry_without_a_scraped_twin_is_left_untouched(): void
    {
        $area = Area::create(['name' => 'Salle Associative', 'slug' => 'salle-associative-dedupe']);

        $manual = Event::create([
            'title' => 'Soirée jeux de société', 'slug' => 'soiree-jeux-de-societe',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'start_date' => '2026-11-01 00:00:00',
        ]);

        Artisan::call('content:dedupe-agenda-manual-entries');

        $this->assertNotSoftDeleted($manual);
    }

    /** Même titre, mais un lieu DIFFÉRENT : pas un doublon, jamais supprimé. */
    public function test_manual_entry_with_a_scraped_twin_at_a_different_venue_is_not_deleted(): void
    {
        $areaA = Area::create(['name' => 'Salle A', 'slug' => 'salle-a-dedupe']);
        $areaB = Area::create(['name' => 'Salle B', 'slug' => 'salle-b-dedupe']);

        $manual = Event::create([
            'title' => 'Concert de jazz', 'slug' => 'concert-jazz-manual',
            'area_id' => $areaA->id, 'status' => 'published', 'source' => 'manual',
            'start_date' => '2026-10-13 00:00:00',
        ]);
        Event::create([
            'title' => 'Concert de jazz', 'slug' => 'concert-jazz-scraped',
            'area_id' => $areaB->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'concert-jazz-2026', 'start_date' => '2026-10-13 19:00:00',
        ]);

        Artisan::call('content:dedupe-agenda-manual-entries');

        $this->assertNotSoftDeleted($manual);
    }

    public function test_dry_run_reports_but_does_not_delete(): void
    {
        $area = Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou-dry-run']);

        $manual = Event::create([
            'title' => 'Le Bijou Comédie Club', 'slug' => 'le-bijou-comedie-club-manual-dry',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'start_date' => '2026-10-13 00:00:00',
        ]);
        Event::create([
            'title' => 'Le Bijou Comédie Club', 'slug' => 'le-bijou-comedie-club-scraped-dry',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => '2473-Le-Bijou-Comedie-Club-dry', 'start_date' => '2026-10-13 19:00:00',
        ]);

        Artisan::call('content:dedupe-agenda-manual-entries', ['--dry-run' => true]);

        $this->assertNotSoftDeleted($manual);
    }

    /** Event fait partie de CLOUDFLARE_PURGE_MODELS/GOOGLE_INDEXING_MODELS/SITEMAP_MODELS — même garde-fou que les autres commandes de nettoyage en lot. */
    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        config(['services.google_indexing.enabled' => true, 'services.google_indexing.credentials_json_base64' => base64_encode('{}')]);

        $area = Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou-observers']);
        Event::create([
            'title' => 'Le Bijou Comédie Club', 'slug' => 'le-bijou-comedie-club-manual-obs',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'manual',
            'start_date' => '2026-10-13 00:00:00',
        ]);
        Event::create([
            'title' => 'Le Bijou Comédie Club', 'slug' => 'le-bijou-comedie-club-scraped-obs',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => '2473-Le-Bijou-Comedie-Club-obs', 'start_date' => '2026-10-13 19:00:00',
        ]);

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();
        Http::fake();

        Artisan::call('content:dedupe-agenda-manual-entries');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();

        Http::assertNothingSent();
    }
}
