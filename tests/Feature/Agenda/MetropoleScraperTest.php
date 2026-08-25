<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\MetropoleDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda "Toulouse Métropole" — reconstruit depuis le VRAI code
 * legacy `updateAgendaforMetropole`/`checkCategoryMetropole` (voir docblock
 * de MetropoleDriver). Vérifié en direct le 25/08/2026 : 300/300 événements
 * importés (plafond API OpenAgenda à 300, voir
 * TECHNICAL_DOCUMENTATION.md §13).
 */
class MetropoleScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Toulouse Métropole', 'slug' => 'toulouse-metropole', 'legacy_id' => 3806]);
        EventCategory::create(['name' => 'Musique', 'slug' => 'musique', 'legacy_id' => 20]);
        EventCategory::create(['name' => 'Divers', 'slug' => 'divers', 'legacy_id' => 18]);

        return ScraperSource::create([
            'name' => 'Toulouse Métropole',
            'type' => 'agenda',
            'driver_class' => MetropoleDriver::class,
            'config' => ['openagenda_slug' => 'toulouse-metropole', 'area_slug' => 'toulouse-metropole'],
            'is_active' => true,
        ]);
    }

    public function test_categorizes_via_types_devenements_keywords(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'openagenda.com/api/agendas/slug/toulouse-metropole/*' => Http::response([
                'events' => [[
                    'slug' => 'le-renne-des-neiges',
                    'title' => ['fr' => 'Le Renne des Neiges'],
                    'nextTiming' => ['begin' => '2026-12-10T20:00:00+02:00', 'end' => '2026-12-10T22:00:00+02:00'],
                    'types-devenements' => [42],
                ]],
                'aggregations' => [
                    'types-devenements' => [['id' => 42, 'value' => 'musique']],
                ],
            ]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'le-renne-des-neiges')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->categories->contains('slug', 'musique'));
    }

    public function test_falls_back_to_divers_when_no_keyword_matches(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'openagenda.com/api/agendas/slug/toulouse-metropole/*' => Http::response([
                'events' => [[
                    'slug' => 'evenement-non-categorise',
                    'title' => ['fr' => 'Évènement non catégorisé'],
                    'nextTiming' => ['begin' => '2026-12-11T20:00:00+02:00'],
                ]],
                'aggregations' => ['types-devenements' => []],
            ]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'evenement-non-categorise')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->categories->contains('slug', 'divers'));

        $this->assertSame('success', ScraperRun::where('source_id', $source->id)->first()->status);
    }
}
