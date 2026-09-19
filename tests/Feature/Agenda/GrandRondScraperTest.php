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
 * docblock de GrandRondDriver).
 *
 * ⚠️ Bug réel trouvé et corrigé (19/09/2026, audit "aucun agenda retourné"
 * demandé par le client) : `#principal` (ancien emplacement de la date,
 * repris du legacy) a disparu du site en direct — CE driver ne créait donc
 * plus AUCUN événement (start_date toujours null → tout `skipped`). Les
 * fixtures ci-dessous reproduisent la structure RÉELLE constatée le
 * 19/09/2026 (date dans `.col-md-5.bloc_type p strong`, plus de `#principal`
 * du tout), et couvrent les 3 formats de texte libre réels trouvés sur le
 * site pour cette date (voir GrandRondDriver::extractDateRangeFromText()).
 */
class GrandRondScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Théâtre du Grand Rond', 'slug' => 'theatre-du-grand-rond-3', 'legacy_id' => 1973]);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre', 'legacy_id' => 4]);

        return ScraperSource::create([
            'name' => 'Théâtre du Grand Rond',
            'type' => 'agenda',
            'driver_class' => GrandRondDriver::class,
            'config' => ['listing_url' => 'https://www.grand-rond.org/programmation', 'area_slug' => 'theatre-du-grand-rond-3'],
            'is_active' => true,
        ]);
    }

    protected function listingHtml(): string
    {
        return <<<'HTML'
            <html><body><div class="container principal"><div class="programmation"><div class="container-fluid">
                <h3>Improbable Tour</h3>
                <img src="visuel.jpg">
                <a class="bouton_plus" href="https://www.grand-rond.org/spectacle/2987">En savoir +</a>
            </div></div></div></body></html>
        HTML;
    }

    protected function detailHtml(string $dateBlock): string
    {
        return <<<HTML
            <html><body>
                <div id="responsiveTabsDemo"><div id="tab-1">Une comédie improvisée.</div></div>
                <div class="col-md-5 bloc_type"><p><strong>{$dateBlock} à 20h<br>Genre : comédie</strong></p></div>
                <a class="bouton_plus" href="https://www.grand-rond.org/reserver/2987">Réserver</a>
            </body></html>
        HTML;
    }

    public function test_creates_event_with_a_cross_month_date_range(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'grand-rond.org/programmation' => Http::response($this->listingHtml()),
            'grand-rond.org/spectacle/2987' => Http::response($this->detailHtml('Du 24 septembre au 3 octobre 2026')),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();
        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'gr2987')->first();
        $this->assertNotNull($event);
        $this->assertSame('Improbable Tour', $event->title);
        $this->assertSame('2026-09-24', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-03', $event->end_date->format('Y-m-d'));
        $this->assertSame('https://www.grand-rond.org/reserver/2987', $event->booking_url);
        $this->assertTrue($event->categories->contains('slug', 'theatre'));
    }

    public function test_creates_event_with_a_same_month_date_range(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'grand-rond.org/programmation' => Http::response($this->listingHtml()),
            'grand-rond.org/spectacle/2987' => Http::response($this->detailHtml('Du 24 au 26 septembre 2026')),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'gr2987')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-09-24', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-09-26', $event->end_date->format('Y-m-d'));
    }

    public function test_creates_event_with_two_dates_joined_by_et(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'grand-rond.org/programmation' => Http::response($this->listingHtml()),
            'grand-rond.org/spectacle/2987' => Http::response($this->detailHtml('Mercredi 30 septembre et samedi 3 octobre 2026')),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'gr2987')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-09-30', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-03', $event->end_date->format('Y-m-d'));
    }
}
