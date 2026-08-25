<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\TheatreDeLaCiteDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour le Théâtre de la Cité (theatre-cite.com), voir le
 * docblock de TheatreDeLaCiteDriver pour le contexte (aucun scraper agenda
 * legacy fonctionnel retrouvé, reconstruit contre le vrai site). Les
 * fragments HTML ci-dessous reprennent fidèlement les classes CSS réelles
 * vérifiées en direct (`programmation-grid__item--evenements`,
 * `spectacle__informations__content__line`...), pas une supposition —
 * confirmé aussi par une exécution réelle (30/30 événements importés sans
 * erreur, voir TECHNICAL_DOCUMENTATION.md §13).
 */
class ScrapeEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(array $configOverrides = []): ScraperSource
    {
        return ScraperSource::create([
            'name' => 'Théâtre de la Cité',
            'type' => 'agenda',
            'driver_class' => TheatreDeLaCiteDriver::class,
            'config' => array_merge([
                'listing_url' => 'https://theatre-cite.com/programmation',
                'area_slug' => 'tnt-theatre-de-la-cite',
                'event_category_slug' => 'theatre',
            ], $configOverrides),
            'is_active' => true,
        ]);
    }

    protected function listingHtml(array $cards): string
    {
        return '<html><body><div class="programmation-grid">'.implode('', $cards).'</div></body></html>';
    }

    protected function card(string $slug, string $title, string $dateText, ?string $timeText = '10:00', ?string $image = 'https://theatre-cite.com/assets/poster.jpg'): string
    {
        $timeSpan = $timeText ? "<span class=\"period-heure\">{$timeText}</span>" : '';
        $imageTag = $image ? "<img class=\"lazy desktop-image\" data-original=\"{$image}\">" : '';

        return <<<HTML
            <div class="programmation-grid__item programmation-grid__item--evenements">
                <a href="https://theatre-cite.com/programmation/2026-2027/evenement/{$slug}/" title="{$title}">
                    {$imageTag}
                    <div class="programmation-grid__item__date">{$dateText}{$timeSpan}</div>
                    <div class="programmation-grid__item__title"><span class="programmation-grid__item__title__inner">{$title}</span></div>
                </a>
            </div>
        HTML;
    }

    protected function detailHtml(?string $bookingUrl = 'https://theatre-cite.notre-billetterie.com/billets?&seance=1971'): string
    {
        $bookingLink = $bookingUrl ? "<a href=\"{$bookingUrl}\">Réserver</a>" : '';

        return <<<HTML
            <html><body>
                <div class="spectacle__informations__content__line">Samedi 26 septembre à 14h Le CUB Durée 1h10 Gratuit sur réservation</div>
                {$bookingLink}
            </body></html>
        HTML;
    }

    public function test_new_event_is_created_from_real_site_structure(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $source = $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('rendez-vous-complicite', 'Rendez-vous Complicité', '26 septembre 2026'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/rendez-vous-complicite/' => Http::response($this->detailHtml()),
        ]);

        $exitCode = $this->artisan('scrape:events')->run();

        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'rendez-vous-complicite')->first();
        $this->assertNotNull($event);
        $this->assertSame('Rendez-vous Complicité', $event->title);
        $this->assertSame('2026-09-26 10:00:00', $event->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('https://theatre-cite.com/assets/poster.jpg', $event->image);
        $this->assertSame('https://theatre-cite.notre-billetterie.com/billets?&seance=1971', $event->booking_url);
        $this->assertSame('published', $event->status);
        $this->assertSame('scraped', $event->source);
        $this->assertTrue($event->categories->contains('slug', 'theatre'));

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_found);
        $this->assertSame(1, $run->items_created);
    }

    public function test_existing_event_is_updated_not_duplicated(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Event::create([
            'title' => 'Ancien titre', 'slug' => 'rendez-vous-complicite',
            'external_ref' => 'rendez-vous-complicite', 'status' => 'published', 'start_date' => now(),
        ]);

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('rendez-vous-complicite', 'Rendez-vous Complicité', '26 septembre 2026'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/rendez-vous-complicite/' => Http::response($this->detailHtml()),
        ]);

        $this->artisan('scrape:events')->run();

        $this->assertSame(1, Event::where('external_ref', 'rendez-vous-complicite')->count());
        $this->assertSame('Rendez-vous Complicité', Event::where('external_ref', 'rendez-vous-complicite')->first()->title);
    }

    public function test_card_with_unparseable_date_is_skipped(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('date-illisible', 'Événement sans date', 'Dates à venir', null),
            ])),
        ]);

        $this->artisan('scrape:events')->run();

        $this->assertDatabaseMissing('events', ['external_ref' => 'date-illisible']);
        $run = ScraperRun::first();
        $this->assertSame(1, $run->items_skipped);
    }

    public function test_upstream_failure_marks_run_as_failed_without_crashing(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $source = $this->makeSource();

        Http::fake([
            'theatre-cite.com/*' => Http::response('Erreur serveur', 500),
        ]);

        $exitCode = $this->artisan('scrape:events')->run();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', ScraperRun::where('source_id', $source->id)->first()->status);
    }

    public function test_inactive_source_is_not_run(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource()->update(['is_active' => false]);

        Http::fake();

        $this->artisan('scrape:events')->run();

        Http::assertNothingSent();
    }
}
