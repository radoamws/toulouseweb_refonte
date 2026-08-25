<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\CasinoBarriereDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour le Casino Théâtre Barrière — reconstruit depuis le
 * VRAI code legacy actif `updateAgendaforCasinoBarriere` (voir docblock de
 * CasinoBarriereDriver, notamment sa LIMITE CONNUE : le site actuel ne rend
 * plus aucun `href` de détail server-side, constaté en direct le 25/08/2026 —
 * 133/133 spectacles trouvés mais ignorés faute de lien exploitable).
 *
 * Le 1er test couvre ce comportement dégradé RÉEL (`.CsnCardShowPortrait`,
 * sans href). Le 2e test couvre la logique de création MULTI-DATES (un
 * `Event` par occurrence datée) avec le sélecteur `.CsnCard` (repli legacy),
 * pour garantir que cette logique reste correcte si le site redevenait
 * proche du legacy ou si un futur correctif (parsing du payload Nuxt)
 * réalimente `href`.
 */
class CasinoBarriereScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Casino Théâtre Barrière', 'slug' => 'casino-theatre-barriere', 'legacy_id' => 1667]);
        EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts', 'legacy_id' => 3]);

        return ScraperSource::create([
            'name' => 'Casino Théâtre Barrière',
            'type' => 'agenda',
            'driver_class' => CasinoBarriereDriver::class,
            'config' => ['listing_url' => 'https://www.casinosbarriere.com/nos-spectacles', 'area_slug' => 'casino-theatre-barriere'],
            'is_active' => true,
        ]);
    }

    public function test_current_site_cards_without_href_are_all_skipped(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'casinosbarriere.com/nos-spectacles' => Http::response(
                '<html><body><div class="CsnNationalShowsPreviewCategory">'
                .'<h2 class="GamingCarousel__title">Concert</h2>'
                .'<div class="CsnCardShowPortrait"><a class=""></a></div>'
                .'</div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame(1, $run->items_found);
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(0, Event::count());
    }

    public function test_creates_one_event_per_dated_occurrence_when_href_available(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'casinosbarriere.com/nos-spectacles' => Http::response(
                '<html><body><div class="CsnNationalShowsPreviewCategory">'
                .'<h2 class="CarouselContainer__title">Concert</h2>'
                .'<a class="CsnCard" href="/spectacle/boulevard-des-airs"><img src="/img/poster.jpg"></a>'
                .'</div></body></html>'
            ),
            'casinosbarriere.com/spectacle/boulevard-des-airs' => Http::response(<<<'HTML'
                <html><body>
                    <h1 class="cartridge__title">Boulevard des Airs</h1>
                    <div class="LocalShowsList"><div class="LocalShows">
                        <div class="cartridge">
                            <ul class="cartridge__list">
                                <li>date</li>
                                <li>du 12 mars 2027 au 14 mars 2027</li>
                                <li>20h30</li>
                            </ul>
                            <ul class="cartridge__details"><li>45€</li></ul>
                            <div class="cartridge__cta"><a href="/reserver/1"></a></div>
                        </div>
                    </div></div>
                </body></html>
            HTML),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'boulevard-des-airs-2027-03-12')->first();
        $this->assertNotNull($event);
        $this->assertSame('Boulevard des Airs', $event->title);
        $this->assertSame('2027-03-12', $event->start_date->format('Y-m-d'));
        $this->assertSame('2027-03-14', $event->end_date->format('Y-m-d'));
        $this->assertTrue($event->categories->contains('slug', 'concerts'));
    }
}
