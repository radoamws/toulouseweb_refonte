<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\InterpreteDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour Les Grands Interprètes — voir la LIMITE CONNUE dans le
 * docblock de InterpreteDriver : le site a changé de CMS depuis l'écriture
 * du legacy (constaté en direct le 25/08/2026) et ce driver ne trouve
 * actuellement AUCUN événement. Ce test verrouille ce comportement dégradé
 * documenté (ne plante pas, retourne juste 0) plutôt que de simuler un faux
 * succès avec des sélecteurs obsolètes.
 */
class InterpreteScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_site_markup_yields_no_events_without_crashing(): void
    {
        Area::create(['name' => 'Les Grands Interprètes', 'slug' => 'les-grands-interpretes', 'legacy_id' => 1177]);
        EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts', 'legacy_id' => 3]);

        $source = ScraperSource::create([
            'name' => 'Les Grands Interprètes',
            'type' => 'agenda',
            'driver_class' => InterpreteDriver::class,
            'config' => ['area_slug' => 'les-grands-interpretes'],
            'is_active' => true,
        ]);

        // Fixture représentative du VRAI nouveau CMS (WordPress "EventChamp"),
        // sans plus aucune trace de `article.fake-link`.
        Http::fake([
            'grandsinterpretes.fr/*' => Http::response(
                '<html><body><div class="gt-event-style-4">Un évènement</div></body></html>'
            ),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, $exitCode);
        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame(0, $run->items_found);
    }
}
