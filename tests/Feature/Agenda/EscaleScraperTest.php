<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\EscaleDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour L'Escale (Tournefeuille) — demande client, 30/09/2026 :
 * remplace l'ancienne source (API JSON Ardei-Soft/VEL) par le vrai site
 * vitrine lescale-tournefeuille.fr — voir le docblock détaillé de
 * EscaleDriver pour le contexte complet (API REST WordPress standard pour la
 * liste, scraping HTML pour le détail par fiche). Les fragments HTML
 * ci-dessous reprennent fidèlement la structure Elementor réelle vérifiée en
 * direct le 30/09/2026 sur 2 fiches très différentes ("Colline" — plage de
 * dates, "Festivals croisés" — date unique).
 */
class EscaleScraperTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = 'https://lescale-tournefeuille.fr/wp-json/wp/v2';

    protected function makeSource(): ScraperSource
    {
        Area::create(['name' => "L'Escale", 'slug' => 'lescale-2', 'legacy_id' => 3563]);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        EventCategory::create(['name' => 'Spectacles', 'slug' => 'spectacles', 'legacy_id' => 7]);

        return ScraperSource::create([
            'name' => "L'Escale (Tournefeuille)",
            'type' => 'agenda',
            'driver_class' => EscaleDriver::class,
            'config' => ['area_slug' => 'lescale-2'],
            'is_active' => true,
        ]);
    }

    protected function fakeTaxonomies(): array
    {
        return [
            self::API_BASE.'/type_de_spectacle?per_page=100' => Http::response([
                ['id' => 6, 'name' => 'Théâtre'],
                ['id' => 48, 'name' => 'Spectacle'],
            ]),
            self::API_BASE.'/etat_du_spectacle?per_page=100' => Http::response([
                ['id' => 67, 'name' => 'Saisons précédentes'],
                ['id' => 68, 'name' => 'Archives des rencontres et ateliers'],
            ]),
        ];
    }

    /**
     * Fragment fidèle à la vraie structure : fil d'Ariane, dates (1 ou 2 h2),
     * horaire, "Lieu :" (label seul) + valeur en frère suivant, tarifs,
     * description (bloc le plus long hors labels connus), un décoy "Le site
     * de la compagnie" (texte substantiel mais pas la description), bouton
     * Billetterie, puis "Galerie" (borne de fin).
     */
    protected function detailHtml(array $dateH2s, string $venueLabel = 'l’Escale', ?string $bookingUrl = 'https://www.ardei-soft.com/tournefeuille/spectacle.html?spectacle=X', string $description = "Un débris de hameau où quatre maisons fleuries d'orchis émergent des blés drus et hauts, quelque part entre la plaine et la montagne. Une histoire de terre, de famille et de silence."): string
    {
        $dateBlocks = implode('', array_map(fn ($d) => "<h2 class=\"elementor-heading-title elementor-size-default\">{$d}</h2>", $dateH2s));
        $bookingBlock = $bookingUrl
            ? "<a class=\"elementor-button elementor-button-link elementor-size-sm\" href=\"{$bookingUrl}\"><span class=\"elementor-button-text\">Billetterie</span></a>"
            : '';

        return <<<HTML
            <html><body>
                <header class="elementor-location-header">
                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">
                        <h2 class="elementor-heading-title elementor-size-default">ANNONCE SITE-WIDE SANS RAPPORT AVEC LA FICHE</h2>
                    </div>
                </header>
                <div class="elementor-location-single">
                    <h1 class="elementor-heading-title">Colline</h1>

                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">L'Escale > Colline</div>
                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">THÉÂTRE Colline</div>

                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">
                        {$dateBlocks}
                        <div class="elementor-widget-text-editor"><div class="elementor-widget-container">Du mardi au samedi 20H30</div></div>
                        <div class="elementor-widget-text-editor"><div class="elementor-widget-container">Lieu : </div></div>
                        <div class="elementor-widget-text-editor"><div class="elementor-widget-container">{$venueLabel}</div></div>
                        <div class="elementor-widget-text-editor"><div class="elementor-widget-container">Tout public à partir de 10 ans</div></div>
                    </div>

                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">
                        <div class="elementor-widget-text-editor"><div class="elementor-widget-container">Tarif plein : 22 €</div></div>
                        <div class="elementor-widget-text-editor"><div class="elementor-widget-container">Tarif réduit : 19 € (sur justificatif)</div></div>
                        {$bookingBlock}
                    </div>

                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">{$description}</div>
                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">Le site de la compagnie Auteur : Jean Giono</div>

                    <div class="e-con-full e-ecs-flex e-flex e-con e-parent">Galerie</div>
                </div>
            </body></html>
        HTML;
    }

    public function test_single_date_show_sets_the_same_start_and_end_date(): void
    {
        $source = $this->makeSource();

        Http::fake([
            ...$this->fakeTaxonomies(),
            self::API_BASE.'/les_spectacles?per_page=100&page=1&_embed=1' => Http::response([[
                'id' => 1, 'slug' => 'festivals-croises',
                'title' => ['rendered' => 'Festivals croisés'],
                'link' => 'https://lescale-tournefeuille.fr/les_spectacles/festivals-croises/',
                'type_de_spectacle' => [6],
                'etat_du_spectacle' => [],
                '_embedded' => ['wp:featuredmedia' => [['source_url' => 'https://lescale-tournefeuille.fr/img/festival.jpg']]],
            ]]),
            self::API_BASE.'/les_spectacles?per_page=100&page=2&_embed=1' => Http::response([]),
            'lescale-tournefeuille.fr/les_spectacles/festivals-croises/' => Http::response(
                $this->detailHtml(['2 octobre'])
            ),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();
        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'festivals-croises')->first();
        $this->assertNotNull($event);
        $this->assertSame('Festivals croisés', $event->title);
        $this->assertSame('https://lescale-tournefeuille.fr/img/festival.jpg', $event->image);
        $this->assertTrue($event->categories->contains('slug', 'theatre'));

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_created);
    }

    /**
     * Cas réel vérifié en direct : plage de dates, le PREMIER h2 n'a ni mois
     * ni année ("30 septembre" alors que la plage va jusqu'à "4 octobre
     * 2026") — emprunte le mois/l'année du second.
     */
    public function test_date_range_borrows_month_and_year_for_the_start_date(): void
    {
        $source = $this->makeSource();

        Http::fake([
            ...$this->fakeTaxonomies(),
            self::API_BASE.'/les_spectacles?per_page=100&page=1&_embed=1' => Http::response([[
                'id' => 2, 'slug' => 'colline',
                'title' => ['rendered' => 'Colline'],
                'link' => 'https://lescale-tournefeuille.fr/les_spectacles/colline/',
                'type_de_spectacle' => [6],
                'etat_du_spectacle' => [],
            ]]),
            self::API_BASE.'/les_spectacles?per_page=100&page=2&_embed=1' => Http::response([]),
            'lescale-tournefeuille.fr/les_spectacles/colline/' => Http::response(
                $this->detailHtml(['30 septembre', '4 octobre 2026'])
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'colline')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-09-30', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-04', $event->end_date->format('Y-m-d'));
    }

    public function test_extracts_venue_price_description_schedule_and_booking_url(): void
    {
        $source = $this->makeSource();

        Http::fake([
            ...$this->fakeTaxonomies(),
            self::API_BASE.'/les_spectacles?per_page=100&page=1&_embed=1' => Http::response([[
                'id' => 3, 'slug' => 'colline',
                'title' => ['rendered' => 'Colline'],
                'link' => 'https://lescale-tournefeuille.fr/les_spectacles/colline/',
                'type_de_spectacle' => [6],
                'etat_du_spectacle' => [],
            ]]),
            self::API_BASE.'/les_spectacles?per_page=100&page=2&_embed=1' => Http::response([]),
            'lescale-tournefeuille.fr/les_spectacles/colline/' => Http::response(
                $this->detailHtml(['30 septembre', '4 octobre 2026'], venueLabel: 'Cinéma Utopia')
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'colline')->first();
        $this->assertNotNull($event);
        $this->assertSame('Cinéma Utopia', $event->venue_name);
        $this->assertSame('Tarif plein : 22 € | Tarif réduit : 19 € (sur justificatif)', $event->price);
        $this->assertSame(['Du mardi au samedi 20H30'], $event->schedule);
        $this->assertSame('https://www.ardei-soft.com/tournefeuille/spectacle.html?spectacle=X', $event->booking_url);
        $this->assertStringContainsString("Un débris de hameau", $event->description);
        // Le décoy "Le site de la compagnie..." ne doit jamais être pris pour la description.
        $this->assertStringNotContainsString('Le site de la compagnie', $event->description);
    }

    public function test_no_billeterie_button_means_no_booking_url(): void
    {
        $source = $this->makeSource();

        Http::fake([
            ...$this->fakeTaxonomies(),
            self::API_BASE.'/les_spectacles?per_page=100&page=1&_embed=1' => Http::response([[
                'id' => 4, 'slug' => 'rencontre-gratuite',
                'title' => ['rendered' => 'Rencontre gratuite'],
                'link' => 'https://lescale-tournefeuille.fr/les_spectacles/rencontre-gratuite/',
                'type_de_spectacle' => [6],
                'etat_du_spectacle' => [],
            ]]),
            self::API_BASE.'/les_spectacles?per_page=100&page=2&_embed=1' => Http::response([]),
            'lescale-tournefeuille.fr/les_spectacles/rencontre-gratuite/' => Http::response(
                $this->detailHtml(['2 octobre'], bookingUrl: null)
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'rencontre-gratuite')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->booking_url);
    }

    /**
     * Demande client, 30/09/2026 : la plupart des 242 entrées de l'API sont
     * des spectacles de saisons précédentes/archivés (taxonomie
     * `etat_du_spectacle`) — jamais scrapés.
     */
    public function test_archived_shows_are_never_scraped(): void
    {
        $source = $this->makeSource();

        Http::fake([
            ...$this->fakeTaxonomies(),
            self::API_BASE.'/les_spectacles?per_page=100&page=1&_embed=1' => Http::response([[
                'id' => 5, 'slug' => 'vieux-spectacle',
                'title' => ['rendered' => 'Vieux spectacle'],
                'link' => 'https://lescale-tournefeuille.fr/les_spectacles/vieux-spectacle/',
                'type_de_spectacle' => [6],
                'etat_du_spectacle' => [67],
            ]]),
            self::API_BASE.'/les_spectacles?per_page=100&page=2&_embed=1' => Http::response([]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, Event::count());
        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame(0, $run->items_found);
    }

    public function test_generic_spectacle_tag_falls_back_to_the_legacy_category(): void
    {
        $source = $this->makeSource();

        Http::fake([
            ...$this->fakeTaxonomies(),
            self::API_BASE.'/les_spectacles?per_page=100&page=1&_embed=1' => Http::response([[
                'id' => 6, 'slug' => 'divers-show',
                'title' => ['rendered' => 'Divers Show'],
                'link' => 'https://lescale-tournefeuille.fr/les_spectacles/divers-show/',
                'type_de_spectacle' => [48], // "Spectacle", fourre-tout — voir docblock du driver.
                'etat_du_spectacle' => [],
            ]]),
            self::API_BASE.'/les_spectacles?per_page=100&page=2&_embed=1' => Http::response([]),
            'lescale-tournefeuille.fr/les_spectacles/divers-show/' => Http::response(
                $this->detailHtml(['2 octobre'])
            ),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'divers-show')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->categories->contains('slug', 'spectacles'));
    }
}
