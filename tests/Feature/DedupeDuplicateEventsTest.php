<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:dedupe-duplicate-events` (demande client, 22/09/2026 : "il faut
 * dédupliquer partout les agendas") — voir docblock de la commande. Cas
 * distinct de DedupeManualAgendaEntriesTest : ici les DEUX lignes sont
 * scrapées, avec des `external_ref` différents pour le même événement
 * (trouvé sur Toulouse Métropole / OpenAgenda, un même événement posté
 * plusieurs fois par l'organisateur sous des slugs différents).
 */
class DedupeDuplicateEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_the_shortest_external_ref_and_deletes_the_rest(): void
    {
        $area = Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole-dedupe']);

        $canonical = Event::create([
            'title' => 'Café lire : le petit cercle littéraire', 'slug' => 'cafe-lire-canonical',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'cafe-lire-le-petit-cercle-litteraire', 'start_date' => '2026-09-26 10:00:00',
        ]);
        $numberedDuplicate = Event::create([
            'title' => 'Café lire : le petit cercle littéraire', 'slug' => 'cafe-lire-numbered',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'cafe-lire-le-petit-cercle-litteraire-2972161', 'start_date' => '2026-09-26 10:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events');

        $this->assertNotSoftDeleted($canonical);
        $this->assertSoftDeleted($numberedDuplicate);
    }

    public function test_handles_a_group_of_three_duplicates(): void
    {
        $area = Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole-triple']);

        $canonical = Event::create([
            'title' => 'Atelier juridique', 'slug' => 'atelier-juridique-canonical',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'atelier-juridique', 'start_date' => '2026-09-28 09:00:00',
        ]);
        $dup1 = Event::create([
            'title' => 'Atelier juridique', 'slug' => 'atelier-juridique-dup1',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'atelier-juridique-135402', 'start_date' => '2026-09-28 09:00:00',
        ]);
        $dup2 = Event::create([
            'title' => 'Atelier juridique', 'slug' => 'atelier-juridique-dup2',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'atelier-juridique-289686', 'start_date' => '2026-09-28 09:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events');

        $this->assertNotSoftDeleted($canonical);
        $this->assertSoftDeleted($dup1);
        $this->assertSoftDeleted($dup2);
    }

    /** Même titre, jour différent : pas un doublon. */
    public function test_same_title_different_day_is_not_deleted(): void
    {
        $area = Area::create(['name' => 'Le Zénith', 'slug' => 'le-zenith-dedupe']);

        $a = Event::create([
            'title' => 'Concert récurrent', 'slug' => 'concert-recurrent-1',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'concert-recurrent-1', 'start_date' => '2026-09-26 20:00:00',
        ]);
        $b = Event::create([
            'title' => 'Concert récurrent', 'slug' => 'concert-recurrent-2',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'concert-recurrent-2', 'start_date' => '2026-10-03 20:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events');

        $this->assertNotSoftDeleted($a);
        $this->assertNotSoftDeleted($b);
    }

    /** Même titre/jour, lieu différent : pas un doublon. */
    public function test_same_title_different_venue_is_not_deleted(): void
    {
        $areaA = Area::create(['name' => 'Salle A', 'slug' => 'salle-a-dup-events']);
        $areaB = Area::create(['name' => 'Salle B', 'slug' => 'salle-b-dup-events']);

        $a = Event::create([
            'title' => 'Spectacle itinérant', 'slug' => 'spectacle-itinerant-a',
            'area_id' => $areaA->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'spectacle-itinerant-a', 'start_date' => '2026-09-26 20:00:00',
        ]);
        $b = Event::create([
            'title' => 'Spectacle itinérant', 'slug' => 'spectacle-itinerant-b',
            'area_id' => $areaB->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'spectacle-itinerant-b', 'start_date' => '2026-09-26 20:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events');

        $this->assertNotSoftDeleted($a);
        $this->assertNotSoftDeleted($b);
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (22/09/2026, vérifié en production après
     * le premier passage) : OpenAgenda a reposté le même événement avec une
     * casse différente ("Les Voyages..." / "Les voyages...") — une
     * comparaison stricte des titres ratait ce doublon.
     */
    public function test_titles_differing_only_by_case_are_treated_as_duplicates(): void
    {
        $area = Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole-case']);

        $canonical = Event::create([
            'title' => "Les voyages de l'Aéroflorale II", 'slug' => 'aeroflorale-canonical',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'les-voyages-de-laeroflorale-ii', 'start_date' => '2026-09-22 10:00:00',
        ]);
        $differentCase = Event::create([
            'title' => "Les Voyages de l'Aéroflorale II", 'slug' => 'aeroflorale-different-case',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'les-voyages-de-laeroflorale-ii-5786005', 'start_date' => '2026-09-22 10:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events');

        $this->assertNotSoftDeleted($canonical);
        $this->assertSoftDeleted($differentCase);
    }

    /**
     * ⚠️ Même découverte : OpenAgenda utilise parfois une espace fine
     * insécable (U+202F, convention typographique française avant "?")
     * plutôt qu'une espace normale — invisible à l'oeil, mais une
     * comparaison stricte de chaînes ratait ce doublon aussi.
     */
    public function test_titles_differing_only_by_a_unicode_space_variant_are_treated_as_duplicates(): void
    {
        $area = Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole-space']);

        $canonical = Event::create([
            'title' => "Comment écrit-on une histoire ?", 'slug' => 'histoire-canonical',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'comment-ecrit-on-une-histoire-149484', 'start_date' => '2026-09-26 10:00:00',
        ]);
        $narrowNoBreakSpace = Event::create([
            'title' => "Comment écrit-on une histoire\u{202F}?", 'slug' => 'histoire-narrow-space',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'comment-ecrit-on-une-histoire-6651312', 'start_date' => '2026-09-26 10:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events');

        $this->assertNotSoftDeleted($canonical);
        $this->assertSoftDeleted($narrowNoBreakSpace);
    }

    public function test_dry_run_reports_but_does_not_delete(): void
    {
        $area = Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole-dry']);

        Event::create([
            'title' => 'Vide-greniers', 'slug' => 'vide-greniers-a',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'vide-greniers', 'start_date' => '2026-09-27 10:00:00',
        ]);
        $duplicate = Event::create([
            'title' => 'Vide-greniers', 'slug' => 'vide-greniers-b',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'vide-greniers-998877', 'start_date' => '2026-09-27 10:00:00',
        ]);

        Artisan::call('content:dedupe-duplicate-events', ['--dry-run' => true]);

        $this->assertNotSoftDeleted($duplicate);
    }

    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        config(['services.google_indexing.enabled' => true, 'services.google_indexing.credentials_json_base64' => base64_encode('{}')]);

        $area = Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole-observers']);
        Event::create([
            'title' => 'Vide-greniers', 'slug' => 'vide-greniers-obs-a',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'vide-greniers-obs', 'start_date' => '2026-09-27 10:00:00',
        ]);
        Event::create([
            'title' => 'Vide-greniers', 'slug' => 'vide-greniers-obs-b',
            'area_id' => $area->id, 'status' => 'published', 'source' => 'scraped',
            'external_ref' => 'vide-greniers-obs-998877', 'start_date' => '2026-09-27 10:00:00',
        ]);

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();
        Http::fake();

        Artisan::call('content:dedupe-duplicate-events');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();

        Http::assertNothingSent();
    }
}
