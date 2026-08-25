<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\GrandRondDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour le Théâtre du Grand Rond (grand-rond.org) —
 * reconstruit depuis le VRAI code legacy `updateAgendaforGrandRond` (voir
 * docblock de GrandRondDriver). Vérifié en direct le 25/08/2026 : la saison
 * 2026-2027 du site source est actuellement une simple annonce (pas encore
 * publiée en détail, "rendez-vous en septembre" — voir
 * TECHNICAL_DOCUMENTATION.md §13), ce test couvre donc le cas nominal avec
 * une fixture construite mais fidèle aux vrais sélecteurs.
 */
class GrandRondScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_event_from_real_site_structure(): void
    {
        Area::create(['name' => 'Théâtre du Grand Rond', 'slug' => 'theatre-du-grand-rond-3', 'legacy_id' => 1973]);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre', 'legacy_id' => 4]);

        $source = ScraperSource::create([
            'name' => 'Théâtre du Grand Rond',
            'type' => 'agenda',
            'driver_class' => GrandRondDriver::class,
            'config' => ['listing_url' => 'https://www.grand-rond.org/programmation', 'area_slug' => 'theatre-du-grand-rond-3'],
            'is_active' => true,
        ]);

        Http::fake([
            'grand-rond.org/programmation' => Http::response(<<<'HTML'
                <html><body><div class="container principal"><div class="programmation"><div class="container-fluid">
                    <h3>Improbable Tour</h3>
                    <img src="visuel.jpg">
                    <a class="bouton_plus" href="https://www.grand-rond.org/spectacle/2987">En savoir +</a>
                </div></div></div></body></html>
            HTML),
            'grand-rond.org/spectacle/2987' => Http::response(<<<'HTML'
                <html><body>
                    <div id="responsiveTabsDemo"><div id="tab-1">Une comédie improvisée.</div></div>
                    <div class="col-md-5 bloc_type"><p><strong>Durée 1h à Genre Comédie</strong></p></div>
                    <div id="principal"><table><tr><td><p><strong>Du 12 mars 2027</strong></p></td></tr></table></div>
                    <a class="bouton_plus" href="https://www.grand-rond.org/reserver/2987">Réserver</a>
                </body></html>
            HTML),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'gr2987')->first();
        $this->assertNotNull($event);
        $this->assertSame('Improbable Tour', $event->title);
        $this->assertSame('2027-03-12', $event->start_date->format('Y-m-d'));
        $this->assertSame('https://www.grand-rond.org/reserver/2987', $event->booking_url);
        $this->assertTrue($event->categories->contains('slug', 'theatre'));
    }
}
