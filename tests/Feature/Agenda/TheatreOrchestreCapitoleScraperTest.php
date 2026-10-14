<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\TheatreOrchestreCapitoleDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda partagé par onct.toulouse.fr (Halle aux Grains) et
 * opera.toulouse.fr (Théâtre du Capitole) — voir docblock de
 * TheatreOrchestreCapitoleDriver. Fixture ci-dessous reproduit la VRAIE
 * structure HTML (microdonnées schema.org) constatée en direct le
 * 15/10/2026 sur les 2 sites (identique à l'octet près).
 */
class TheatreOrchestreCapitoleScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(string $listingUrl, string $areaName, string $areaSlug): ScraperSource
    {
        Area::create(['name' => $areaName, 'slug' => $areaSlug]);
        EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);

        return ScraperSource::create([
            'name' => $areaName,
            'type' => 'agenda',
            'driver_class' => TheatreOrchestreCapitoleDriver::class,
            'config' => ['listing_url' => $listingUrl, 'area_slug' => $areaSlug, 'event_category_slug' => 'concerts'],
            'is_active' => true,
        ]);
    }

    /** Reproduit fidèlement un vrai `.card-item` (microdonnées schema.org/Event, voir docblock de la classe). */
    protected function cardHtml(string $href, string $title, string $isoDate, string $taxonomy, string $imageUrl): string
    {
        return <<<HTML
            <div class="card-item" itemscope itemtype="https://schema.org/Event">
                <div class="card-item-infos">
                    <div class="card-item-header">
                        <h3 class="card-item-title" itemprop="name">{$title}</h3>
                        <time class="card-item-date" datetime="{$isoDate}" itemprop="startDate" content="{$isoDate}">
                            <p class="card-item-date-date">Dim. 18 octobre 2026 - 11h00</p>
                        </time>
                    </div>
                    <p class="card-item-description" itemprop="description">Sous la direction de Dylan Corlay</p>
                    <a class="card-item-link" href="{$href}">Découvrir {$title}</a>
                </div>
                <ul class="card-item-taxonomies"><li>{$taxonomy}</li></ul>
                <figure class="card-item-figure"><img itemprop="image" class="pure-img" src="{$imageUrl}" alt=""></figure>
            </div>
        HTML;
    }

    protected function detailHtml(string $price, string $bookingUrl): string
    {
        return <<<HTML
            <html><body>
                <li class="single-item-info single-item-price">
                    <span class="single-item-info-entitled">Tarif</span>
                    <span class="single-item-info-data" itemprop="price">{$price}</span>
                </li>
                <div class="single-item-status">
                    <a href='{$bookingUrl}' target="_blank" rel="noreferrer noopener" class="btn">Réserver</a>
                </div>
            </body></html>
        HTML;
    }

    public function test_creates_event_from_onct_listing_with_iso_date_and_image(): void
    {
        $source = $this->makeSource('https://onct.toulouse.fr/agenda/', 'Halle aux Grains', 'halle-aux-grains');

        Http::fake([
            'onct.toulouse.fr/agenda/' => Http::response(
                '<html><body>'.$this->cardHtml(
                    'https://onct.toulouse.fr/pierre-et-le-loup-7912342/',
                    'Pierre et le Loup',
                    '2026-10-18T11:00:00+02:00',
                    'Concert en famille',
                    'https://cdn.openagenda.com/main/a9b7eca8ce8c45d1a82584ff323f1537.base.image.jpg'
                ).'</body></html>'
            ),
            'onct.toulouse.fr/pierre-et-le-loup-7912342/' => Http::response(
                $this->detailHtml('De 8 à 25 €', 'https://billetterie.theatreorchestre.toulouse-metropole.fr/selection/event/date?productId=1')
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'pierre-et-le-loup-7912342')->first();
        $this->assertNotNull($event);
        $this->assertSame('Pierre et le Loup', $event->title);
        $this->assertSame('2026-10-18 11:00:00', $event->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('halle-aux-grains', $event->area->slug);
        $this->assertSame('https://cdn.openagenda.com/main/a9b7eca8ce8c45d1a82584ff323f1537.base.image.jpg', $event->image);
    }

    /** Même driver, 2e site (opera.toulouse.fr), config area_slug différente — voir docblock de la classe. */
    public function test_same_driver_serves_the_opera_site_with_a_different_area(): void
    {
        $source = $this->makeSource('https://opera.toulouse.fr/agenda/', 'Théâtre du Capitole', 'theatre-du-capitole');

        Http::fake([
            'opera.toulouse.fr/agenda/' => Http::response(
                '<html><body>'.$this->cardHtml(
                    'https://opera.toulouse.fr/maitres-americains/',
                    'Maîtres américains',
                    '2026-10-17T20:00:00+02:00',
                    'Ballet',
                    'https://cdn.openagenda.com/main/3f1d1cf4575240aba43c45df247bee59.base.image.jpg'
                ).'</body></html>'
            ),
            'opera.toulouse.fr/maitres-americains/' => Http::response(
                $this->detailHtml('De 8 à 71 €', 'https://billetterie.theatreorchestre.toulouse-metropole.fr/selection/event/date?productId=2')
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'maitres-americains')->first();
        $this->assertNotNull($event);
        $this->assertSame('theatre-du-capitole', $event->area->slug);
    }

    /**
     * Prix/billetterie réels (2e requête HTTP vers la page de détail, même
     * principe que GaronneDriver::fetchTicketingInfo()) — jamais présents
     * sur la carte de la page listing elle-même.
     */
    public function test_fetches_real_price_and_booking_link_from_the_detail_page(): void
    {
        $source = $this->makeSource('https://onct.toulouse.fr/agenda/', 'Halle aux Grains', 'halle-aux-grains');

        Http::fake([
            'onct.toulouse.fr/agenda/' => Http::response(
                '<html><body>'.$this->cardHtml(
                    'https://onct.toulouse.fr/pierre-et-le-loup-7912342/',
                    'Pierre et le Loup',
                    '2026-10-18T11:00:00+02:00',
                    'Concert en famille',
                    'https://cdn.openagenda.com/main/poster.jpg'
                ).'</body></html>'
            ),
            'onct.toulouse.fr/pierre-et-le-loup-7912342/' => Http::response(
                $this->detailHtml('De 8 à 25 €', 'https://billetterie.theatreorchestre.toulouse-metropole.fr/selection/event/date?productId=10229813217925')
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'pierre-et-le-loup-7912342')->first();
        $this->assertSame('De 8 à 25 €', $event->price);
        $this->assertSame('https://billetterie.theatreorchestre.toulouse-metropole.fr/selection/event/date?productId=10229813217925', $event->booking_url);
    }

    /** Pas de lien billetterie trouvé sur la page de détail : repli sur la page de l'événement elle-même. */
    public function test_falls_back_to_the_event_page_when_no_ticketing_link_is_found(): void
    {
        $source = $this->makeSource('https://onct.toulouse.fr/agenda/', 'Halle aux Grains', 'halle-aux-grains');

        Http::fake([
            'onct.toulouse.fr/agenda/' => Http::response(
                '<html><body>'.$this->cardHtml(
                    'https://onct.toulouse.fr/pierre-et-le-loup-7912342/',
                    'Pierre et le Loup',
                    '2026-10-18T11:00:00+02:00',
                    'Concert en famille',
                    'https://cdn.openagenda.com/main/poster.jpg'
                ).'</body></html>'
            ),
            'onct.toulouse.fr/pierre-et-le-loup-7912342/' => Http::response('<html><body>Pas de billetterie trouvée ici.</body></html>'),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'pierre-et-le-loup-7912342')->first();
        $this->assertSame('https://onct.toulouse.fr/pierre-et-le-loup-7912342/', $event->booking_url);
    }

    public function test_skips_a_card_without_a_parsable_iso_date(): void
    {
        $source = $this->makeSource('https://onct.toulouse.fr/agenda/', 'Halle aux Grains', 'halle-aux-grains');

        Http::fake([
            'onct.toulouse.fr/agenda/' => Http::response(
                '<html><body><div class="card-item" itemscope itemtype="https://schema.org/Event">
                    <h3 class="card-item-title" itemprop="name">Sans date</h3>
                    <a class="card-item-link" href="https://onct.toulouse.fr/sans-date/">Découvrir</a>
                </div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, Event::count());
    }

    public function test_rerun_is_idempotent_and_updates_the_same_event(): void
    {
        $source = $this->makeSource('https://onct.toulouse.fr/agenda/', 'Halle aux Grains', 'halle-aux-grains');

        $html = '<html><body>'.$this->cardHtml(
            'https://onct.toulouse.fr/pierre-et-le-loup-7912342/', 'Pierre et le Loup',
            '2026-10-18T11:00:00+02:00', 'Concert en famille', 'https://cdn.openagenda.com/main/poster.jpg'
        ).'</body></html>';

        Http::fake([
            'onct.toulouse.fr/agenda/' => Http::response($html),
            'onct.toulouse.fr/pierre-et-le-loup-7912342/' => Http::response($this->detailHtml('25 €', 'https://billetterie.theatreorchestre.toulouse-metropole.fr/x')),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();
        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(1, Event::where('external_ref', 'pierre-et-le-loup-7912342')->count());
    }
}
