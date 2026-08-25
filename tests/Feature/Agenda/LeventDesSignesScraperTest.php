<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\LeventDesSignesDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour Le Vent des Signes (leventdessignes.fr) — reconstruit
 * depuis le VRAI code legacy `updateAgendaforLeventdessignes` (voir docblock
 * de LeventDesSignesDriver). Vérifié en direct le 25/08/2026 : 48 cartes
 * trouvées, 41 événements datables importés (7 pages éditoriales sans date
 * réelle, correctement ignorées — voir TECHNICAL_DOCUMENTATION.md §13).
 */
class LeventDesSignesScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Le Vent des Signes', 'slug' => 'le-vent-des-signes', 'legacy_id' => 57]);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre', 'legacy_id' => 4]);

        return ScraperSource::create([
            'name' => 'Le Vent des Signes',
            'type' => 'agenda',
            'driver_class' => LeventDesSignesDriver::class,
            'config' => ['listing_url' => 'https://www.leventdessignes.fr', 'area_slug' => 'le-vent-des-signes'],
            'is_active' => true,
        ]);
    }

    public function test_two_part_date_range_without_month_on_first_part(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'leventdessignes.fr' => Http::response(
                '<html><body><article><a href="https://www.leventdessignes.fr/class/festival-lhistoire-a-venir/"></a></article></body></html>'
            ),
            'leventdessignes.fr/class/festival-lhistoire-a-venir/' => Http::response(<<<'HTML'
                <html><body>
                    <h1 class="single-title">L'histoire à venir</h1>
                    <div class="single-dates">13 > 17 MAI</div>
                </body></html>
            HTML),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'festival-lhistoire-a-venir')->first();
        $this->assertNotNull($event);
        $this->assertSame(5, $event->start_date->month);
        $this->assertSame(13, $event->start_date->day);
        $this->assertSame(17, $event->end_date->day);
    }

    public function test_editorial_page_without_a_real_date_is_skipped(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'leventdessignes.fr' => Http::response(
                '<html><body><article><a href="https://www.leventdessignes.fr/class/edito/"></a></article></body></html>'
            ),
            'leventdessignes.fr/class/edito/' => Http::response(
                '<html><body><h1 class="single-title">Édito</h1><div class="single-dates">2026-2027</div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertDatabaseMissing('events', ['external_ref' => 'edito']);
        $this->assertSame(1, ScraperRun::where('source_id', $source->id)->first()->items_skipped);
    }
}
