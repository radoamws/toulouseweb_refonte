<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\BikiniDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour Le Bikini (lebikini.com) — reconstruit depuis le VRAI
 * code legacy `updateAgendaforBikini`/`updateAgendaforBikini2` (voir docblock
 * de BikiniDriver). Fixture ci-dessous reproduit la VRAIE structure HTML
 * constatée en direct le 15/10/2026 (`/programmation/`, 25 cartes réelles).
 */
class BikiniScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Le Bikini', 'slug' => 'le-bikini', 'legacy_id' => 73]);
        EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);

        return ScraperSource::create([
            'name' => 'Le Bikini',
            'type' => 'agenda',
            'driver_class' => BikiniDriver::class,
            'config' => ['listing_url' => 'https://lebikini.com/programmation/', 'area_slug' => 'le-bikini', 'event_category_slug' => 'concerts'],
            'is_active' => true,
        ]);
    }

    /** Reproduit fidèlement un vrai `.event-card` (voir docblock de BikiniDriver). */
    protected function eventCardHtml(string $href, string $title, string $venue, string $dateTimeText, string $price, string $imageUrl): string
    {
        $encodedImage = urlencode($imageUrl);

        return <<<HTML
            <div class="event-card flex flex-col lg:flex-row" id="card">
                <a class="relative lg:w-[40%] xl:w-1/3" href="{$href}">
                    <img alt="" src="/_next/image?url={$encodedImage}&amp;w=3840&amp;q=70"/>
                </a>
                <div class="flex flex-1 flex-col justify-between bg-white p-4 lg:w-[60%] xl:w-2/3">
                    <a class="flex-wrap border-b border-tabline pb-3 text-lg md:flex" href="{$href}">
                        <div class="md:mr-1">{$dateTimeText}</div>
                        <div class="border-primary font-semibold md:ml-1 md:border-l md:pl-2">{$venue}</div>
                    </a>
                    <a class="flex-1 space-y-2 py-2" href="{$href}">
                        <h3 class="my-0 line-clamp-2 text-[2rem] font-bold lg:text-2xl">{$title}</h3>
                        <div><div class="text-base capitalize">Electro</div></div>
                    </a>
                    <div class="flex gap-2">
                        <div class="rounded-md bg-background p-1 px-2 font-semibold">{$price}</div>
                    </div>
                    <button>Réserver</button>
                </div>
            </div>
        HTML;
    }

    public function test_creates_event_with_date_parsed_from_the_url_and_time_from_the_text(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'lebikini.com/programmation/' => Http::response(
                '<html><body>'.$this->eventCardHtml(
                    '/2026/10/15/fakear',
                    'FAKEAR + MAÏCEE',
                    'Le Bikini',
                    'Jeudi 15 octobre 2026 à 19h30',
                    'à partir de 30€',
                    'https://cdn.sanity.io/images/rf3cqo3h/production/poster.png'
                ).'</body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', '2026-10-15-fakear')->first();
        $this->assertNotNull($event);
        $this->assertSame('FAKEAR + MAÏCEE', $event->title);
        $this->assertSame('2026-10-15 19:30:00', $event->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('à partir de 30€', $event->price);
        $this->assertSame('https://cdn.sanity.io/images/rf3cqo3h/production/poster.png', $event->image);
        $this->assertSame('https://lebikini.com/2026/10/15/fakear', $event->booking_url);
        $this->assertTrue($event->categories->contains('slug', 'concerts'));
    }

    /**
     * ⚠️ Découverte en construisant ce driver (vérifiée en direct) : la page
     * liste aussi des événements co-produits dans D'AUTRES salles (ex. "La
     * Halle aux Grains") — `area_id` reste fixé sur "Le Bikini" (la source),
     * mais `venue_name` doit porter le VRAI lieu, pas "Le Bikini". Même
     * convention que MetropoleDriver.
     */
    public function test_captures_the_real_venue_when_different_from_le_bikini_itself(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'lebikini.com/programmation/' => Http::response(
                '<html><body>'.$this->eventCardHtml(
                    '/2026/11/03/some-show',
                    'Some Show',
                    'La Halle aux Grains',
                    'Mardi 3 novembre 2026 à 20h00',
                    '25€',
                    'https://cdn.sanity.io/images/rf3cqo3h/production/other.png'
                ).'</body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', '2026-11-03-some-show')->first();
        $this->assertNotNull($event);
        $this->assertSame('La Halle aux Grains', $event->venue_name);
        // `area_id` reste la source Le Bikini, pas un Area "Halle aux Grains" recréé à la volée.
        $this->assertSame('le-bikini', $event->area->slug);
    }

    public function test_skips_a_card_without_a_parsable_date_in_its_url(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'lebikini.com/programmation/' => Http::response(
                '<html><body>'.$this->eventCardHtml(
                    '/evenement-sans-date',
                    'Sans date',
                    'Le Bikini',
                    'Date à confirmer',
                    '20€',
                    'https://cdn.sanity.io/images/rf3cqo3h/production/x.png'
                ).'</body></html>'
            ),
        ]);

        $result = $this->artisan('scrape:events', ['--source' => $source->id]);
        $result->run();

        $this->assertSame(0, Event::count());
    }

    public function test_rerun_is_idempotent_and_updates_the_same_event(): void
    {
        $source = $this->makeSource();

        $html = '<html><body>'.$this->eventCardHtml(
            '/2026/10/15/fakear', 'FAKEAR + MAÏCEE', 'Le Bikini', 'Jeudi 15 octobre 2026 à 19h30', '30€',
            'https://cdn.sanity.io/images/rf3cqo3h/production/poster.png'
        ).'</body></html>';

        Http::fake(['lebikini.com/programmation/' => Http::response($html)]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();
        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(1, Event::where('external_ref', '2026-10-15-fakear')->count());
    }
}
