<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\ZenithDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour le Zénith Toulouse Métropole — reconstruit depuis le
 * VRAI code legacy `updateAgendaforZenith` (voir docblock de ZenithDriver).
 * Fixture JSON conforme à la VRAIE forme de réponse OpenAgenda, vérifiée en
 * direct le 25/08/2026 (94/94 événements importés sans erreur) — voir
 * TECHNICAL_DOCUMENTATION.md §13.
 */
class ZenithScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_event_from_real_openagenda_shape(): void
    {
        Area::create(['name' => 'Le Zénith', 'slug' => 'le-zenith', 'legacy_id' => 37]);
        EventCategory::create(['name' => 'Divers', 'slug' => 'divers', 'legacy_id' => 18]);

        $source = ScraperSource::create([
            'name' => 'Zénith Toulouse Métropole',
            'type' => 'agenda',
            'driver_class' => ZenithDriver::class,
            'config' => ['openagenda_slug' => 'zenith-toulouse-metropole', 'area_slug' => 'le-zenith'],
            'is_active' => true,
        ]);

        Http::fake([
            'openagenda.com/api/agendas/slug/zenith-toulouse-metropole/*' => Http::response([
                'total' => 1,
                'events' => [[
                    'slug' => 'la-dame-de-pierre-2475047',
                    'title' => ['fr' => 'LA DAME DE PIERRE'],
                    'description' => ['fr' => 'Un spectacle.'],
                    'image' => ['filename' => 'abc.image.jpg', 'base' => 'https://img.openagenda.com/main/'],
                    'nextTiming' => ['begin' => '2026-09-23T20:00:00+02:00', 'end' => '2026-09-23T23:59:00+02:00'],
                ]],
            ]),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'la-dame-de-pierre-2475047')->first();
        $this->assertNotNull($event);
        $this->assertSame('LA DAME DE PIERRE', $event->title);
        $this->assertSame('https://img.openagenda.com/main/abc.image.jpg', $event->image);
        $this->assertSame('2026-09-23 20:00:00', $event->start_date->format('Y-m-d H:i:s'));
        $this->assertTrue($event->categories->contains('slug', 'divers'));

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_created);
    }

    public function test_events_without_nexttiming_are_ignored(): void
    {
        Area::create(['name' => 'Le Zénith', 'slug' => 'le-zenith', 'legacy_id' => 37]);
        EventCategory::create(['name' => 'Divers', 'slug' => 'divers', 'legacy_id' => 18]);

        $source = ScraperSource::create([
            'name' => 'Zénith Toulouse Métropole',
            'type' => 'agenda',
            'driver_class' => ZenithDriver::class,
            'config' => ['openagenda_slug' => 'zenith-toulouse-metropole', 'area_slug' => 'le-zenith'],
            'is_active' => true,
        ]);

        Http::fake([
            'openagenda.com/api/agendas/slug/zenith-toulouse-metropole/*' => Http::response([
                'events' => [[
                    'slug' => 'passe',
                    'title' => ['fr' => 'Ancien évènement'],
                    'nextTiming' => null,
                ]],
            ]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertDatabaseMissing('events', ['external_ref' => 'passe']);
        $this->assertSame(0, ScraperRun::where('source_id', $source->id)->first()->items_found);
    }
}
