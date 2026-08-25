<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\GaronneDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour le Théâtre Garonne (theatregaronne.com) — reconstruit
 * depuis le VRAI code legacy `updateAgendaforGaronne`/`getIdTheaterGaronne`
 * (voir docblock de GaronneDriver). Vérifié en direct le 25/08/2026 :
 * 29/29 spectacles importés — y compris un bug legacy réel découvert et
 * corrigé ici (voir 2e test ci-dessous : dates à 2 nœuds `<time>` où le 1er
 * nœud ne porte que le jour, sans mois, contrairement à ce que le code
 * legacy suppose).
 */
class GaronneScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Théâtre Garonne', 'slug' => 'theatre-garonne', 'legacy_id' => 6]);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre', 'legacy_id' => 4]);

        return ScraperSource::create([
            'name' => 'Théâtre Garonne',
            'type' => 'agenda',
            'driver_class' => GaronneDriver::class,
            'config' => ['listing_url' => 'https://www.theatregaronne.com/saison', 'area_slug' => 'theatre-garonne'],
            'is_active' => true,
        ]);
    }

    protected function listingHtml(string $class, string $title, string $href): string
    {
        return <<<HTML
            <html><body>
                <article class="carte {$class}">
                    <div class="carte--spectacle__visuel"><img src="/img/poster.jpg"></div>
                    <div class="carte--spectacle__title"><h2>{$title}</h2><a href="{$href}"></a></div>
                </article>
            </body></html>
        HTML;
    }

    public function test_creates_event_with_single_date_node(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'theatregaronne.com/saison' => Http::response($this->listingHtml('carte--theatre', 'Celui qui voit', '/spectacle/2026-2027/celui-qui-voit')),
            'theatregaronne.com/spectacle/2026-2027/celui-qui-voit' => Http::response(
                '<html><body><div class="delta--dates"><time>12 Sep</time></div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'celui-qui-voit')->first();
        $this->assertNotNull($event);
        $this->assertSame('Celui qui voit', $event->title);
        $this->assertTrue($event->categories->contains('slug', 'theatre'));
    }

    public function test_two_date_nodes_share_the_month_of_the_second_node(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'theatregaronne.com/saison' => Http::response($this->listingHtml('carte--theatre', 'Les Gaulois', '/spectacle/2026-2027/les-gaulois')),
            'theatregaronne.com/spectacle/2026-2027/les-gaulois' => Http::response(
                '<html><body><div class="delta--dates"><time>07</time><time>15 Oct</time></div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'les-gaulois')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-10-07', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-15', $event->end_date->format('Y-m-d'));
    }
}
