<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\ArdeiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour L'Aria (Cornebarrieu) — même plateforme Ardei-Soft/VEL
 * que EscaleDriver (voir son docblock et celui de AbstractArdeiSoftDriver),
 * mais avec catégorisation par thème (`updateAgendaforArdei`, voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~4735).
 * Vérifié en direct le 25/08/2026 : 18/18 spectacles importés.
 */
class ArdeiScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_category_via_theme_label(): void
    {
        Area::create(['name' => 'Aria', 'slug' => 'aria', 'legacy_id' => 3708]);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre', 'legacy_id' => 4]);
        EventCategory::create(['name' => 'Divers', 'slug' => 'divers', 'legacy_id' => 18]);

        $source = ScraperSource::create([
            'name' => 'Aria (Cornebarrieu)',
            'type' => 'agenda',
            'driver_class' => ArdeiDriver::class,
            'config' => ['town_slug' => 'cornebarrieu', 'area_slug' => 'aria', 'tarifs_group' => 1],
            'is_active' => true,
        ]);

        Http::fake([
            'www.ardei-soft.com/cornebarrieu/SenousritPGI*' => Http::response([
                'spectacles' => [[
                    's' => 'une-piece-de-theatre',
                    'lLbl' => 'Une pièce de théâtre',
                    'fmm1' => 'visuel.jpg',
                    'dateD' => [2026, 11, 5, 20, 0],
                    'dateF' => [2026, 11, 5, 22, 0],
                    't' => [7],
                ]],
                'themes' => [
                    ['t' => [7], 'lbl' => 'Théâtre'],
                ],
            ]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'une-piece-de-theatre')->first();
        $this->assertNotNull($event);
        $this->assertSame('Une pièce de théâtre', $event->title);
        $this->assertTrue($event->categories->contains('slug', 'theatre'));
    }
}
