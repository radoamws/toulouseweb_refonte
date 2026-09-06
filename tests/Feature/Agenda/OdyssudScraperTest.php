<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\OdyssudDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour Odyssud Blagnac (odyssud.com) — reconstruit depuis le
 * VRAI code legacy `updateAgendaforOdyssud`/`getIdTheaterOdyssud` (voir
 * docblock de OdyssudDriver). Vérifié en direct le 25/08/2026 : 48/48
 * spectacles importés — y compris 2 bugs legacy réels découverts et corrigés
 * ici (voir les 2 tests ci-dessous).
 */
class OdyssudScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => 'Odyssud Blagnac', 'slug' => 'odyssud-blagnac', 'legacy_id' => 16]);
        EventCategory::create(['name' => 'Danse', 'slug' => 'danse', 'legacy_id' => 12]);

        return ScraperSource::create([
            'name' => 'Odyssud Blagnac',
            'type' => 'agenda',
            'driver_class' => OdyssudDriver::class,
            'config' => ['listing_url' => 'https://www.odyssud.com/spectacles/normal', 'area_slug' => 'odyssud-blagnac'],
            'is_active' => true,
        ]);
    }

    protected function listingHtml(string $href): string
    {
        return <<<HTML
            <html><body><div class="spectacles--search--content future">
                <article>
                    <div class="field--name-name">Hourvari</div>
                    <picture><source srcset="/img/hourvari.jpg?w=300"></picture>
                    <div class="entity-infos"><div class="link-zone"><a href="{$href}"></a></div></div>
                </article>
            </div></body></html>
        HTML;
    }

    public function test_three_spans_with_empty_first_span_is_a_single_date(): void
    {
        // Constaté en direct : le site laisse parfois le 1er <span> vide
        // (pictogramme sans texte) quand il n'y a qu'UNE seule date, pas une
        // plage — le legacy suppose à tort 3 spans = toujours une plage.
        $source = $this->makeSource();

        Http::fake([
            'odyssud.com/spectacles/normal' => Http::response($this->listingHtml('/spectacles/cirque/hourvari')),
            'odyssud.com/spectacles/cirque/hourvari' => Http::response(
                '<html><body><div class="duration"><div class="duration-day"><span></span></div>'
                .'<div class="duration-day"><span></span></div>'
                .'<div class="duration-day"><span>12 décembre</span></div></div>'
                .'<div class="field field--name-discipline">Danse</div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'hourvari')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-12-12', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-12-12', $event->end_date->format('Y-m-d'));
        $this->assertTrue($event->categories->contains('slug', 'danse'));
    }

    public function test_falls_back_to_plain_duration_day_text_without_span(): void
    {
        // Constaté en direct : le site ne rend plus systématiquement de
        // <span> imbriqué — le texte "D mois" est parfois directement dans
        // `.duration-day`.
        $source = $this->makeSource();

        Http::fake([
            'odyssud.com/spectacles/normal' => Http::response($this->listingHtml('/spectacles/musiques/commandos-percu')),
            'odyssud.com/spectacles/musiques/commandos-percu' => Http::response(
                '<html><body><div class="duration"><div class="duration-day">24 septembre</div></div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'commandos-percu')->first();
        $this->assertNotNull($event);
        $this->assertSame(9, $event->start_date->month);
        $this->assertSame(24, $event->start_date->day);
    }

    /**
     * Horaire (06/09/2026, corrigé suite à l'audit §18 de
     * TECHNICAL_DOCUMENTATION.md) : une entrée "jour: heure" par
     * représentation, silencieusement jamais reportée avant ce correctif.
     */
    public function test_schedule_is_captured_per_representation(): void
    {
        $source = $this->makeSource();

        Http::fake([
            'odyssud.com/spectacles/normal' => Http::response($this->listingHtml('/spectacles/cirque/hourvari')),
            'odyssud.com/spectacles/cirque/hourvari' => Http::response(
                '<html><body><div class="duration"><div class="duration-day"><span>12 décembre</span></div></div>'
                .'<div class="field--name-dates">'
                .'<div class="date-field-item"><span class="duration-day">Vendredi 12 décembre</span><span class="duration-hours">20h30</span></div>'
                .'<div class="date-field-item"><span class="duration-day">Samedi 13 décembre</span><span class="duration-hours">18h00</span></div>'
                .'</div></body></html>'
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'hourvari')->first();
        $this->assertNotNull($event);
        $this->assertSame(['Vendredi 12 décembre: 20h30', 'Samedi 13 décembre: 18h00'], $event->schedule);
    }
}
