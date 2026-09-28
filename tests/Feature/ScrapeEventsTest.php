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
 * `spectacle__informations__content__line`, `spectacle__period`,
 * `is-billeterie`...), pas une supposition — confirmé aussi par une
 * exécution réelle, voir TECHNICAL_DOCUMENTATION.md §13/§59.
 *
 * ⚠️ Demande client, 28/09/2026 : la date faisait foi jusqu'ici via la carte
 * de LISTING (`.programmation-grid__item__date`, jamais une plage) — elle
 * vient désormais de la fiche DÉTAIL (`.spectacle__period`), seule à
 * afficher la vraie plage de dates (voir TheatreDeLaCiteDriver::parsePeriod()).
 * `detailHtml()` reflète donc ce nouveau champ, `card()` garde son
 * `$dateText`/`$timeText` pour la fidélité du fixture de listing mais ces
 * valeurs n'influencent plus `start_date`/`end_date`.
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

    protected function card(string $slug, string $title, string $dateText, ?string $timeText = '10:00', ?string $image = 'https://theatre-cite.com/assets/poster.jpg', ?string $subtitle = null, string $type = 'evenements'): string
    {
        $timeSpan = $timeText ? "<span class=\"period-heure\">{$timeText}</span>" : '';
        $imageTag = $image ? "<img class=\"lazy desktop-image\" data-original=\"{$image}\">" : '';
        // Certaines cartes "événement" (ex. "Bord de scène", rejoué pour
        // plusieurs pièces différentes) portent le vrai nom distinctif dans
        // ce sous-titre, séparé du titre — voir le docblock du correctif du
        // 19/09/2026 dans TheatreDeLaCiteDriver::run().
        $subtitleTag = $subtitle ? "<div class=\"programmation-grid__item__subtitle\">{$subtitle}</div>" : '';
        // Le segment d'URL réel est au SINGULIER ("evenement"/"spectacle")
        // alors que la classe CSS du type de carte est au PLURIEL
        // ("--evenements"/"--spectacles") — vérifié en direct sur le vrai
        // site le 19/09/2026, une incohérence propre au site source.
        $urlSegment = rtrim($type, 's');

        return <<<HTML
            <div class="programmation-grid__item programmation-grid__item--{$type}">
                <a href="https://theatre-cite.com/programmation/2026-2027/{$urlSegment}/{$slug}/" title="{$title}">
                    {$imageTag}
                    <div class="programmation-grid__item__date">{$dateText}{$timeSpan}</div>
                    <div class="programmation-grid__item__title"><span class="programmation-grid__item__title__inner">{$title}</span>{$subtitleTag}</div>
                </a>
            </div>
        HTML;
    }

    protected function detailHtml(string $periodText = '26 septembre 2026', ?string $timeText = '10h00', ?string $bookingUrl = 'https://theatre-cite.notre-billetterie.com/billets?&seance=1971'): string
    {
        $bookingBlock = $bookingUrl
            ? "<div class=\"spectacle__representations__item is-billeterie\"><a href=\"{$bookingUrl}\" target=\"_blank\">Réserver une place</a></div>"
            : '';
        $timeSpan = $timeText ? "<span class=\"period-heure\">{$timeText}</span>" : '';

        return <<<HTML
            <html><body>
                <div class="spectacle__period">{$periodText}{$timeSpan}</div>
                <div class="spectacle__informations__content__line">Samedi 26 septembre à 14h Le CUB Durée 1h10 Gratuit sur réservation</div>
                {$bookingBlock}
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
            'theatre-cite.com/programmation/2026-2027/evenement/date-illisible/' => Http::response(
                $this->detailHtml(periodText: 'Dates à venir', timeText: null)
            ),
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

    /**
     * ⚠️ Bug réel trouvé et corrigé (19/09/2026, signalé par le client :
     * "des doublons" sur l'agenda). Vérifié en direct sur theatre-cite.com :
     * les cartes "Bord de scène" (un format de rencontre après spectacle,
     * rejoué pour ~15 pièces différentes) portent leur vrai nom distinctif
     * dans un sous-titre séparé, jamais lu jusqu'ici — d'où une quinzaine de
     * fiches toutes titrées identiquement "Bord de scène" sur le site
     * public, illisibles les unes des autres.
     */
    public function test_subtitle_is_appended_to_the_title_when_present(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('bord-de-scene-9-minutes-43', 'Bord de scène', '8 octobre 2026', subtitle: '9 minutes 43'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/bord-de-scene-9-minutes-43/' => Http::response($this->detailHtml()),
        ]);

        $this->artisan('scrape:events')->run();

        $event = Event::where('external_ref', 'bord-de-scene-9-minutes-43')->first();
        $this->assertNotNull($event);
        $this->assertSame('Bord de scène — 9 minutes 43', $event->title);
    }

    /** Une carte SANS sous-titre (l'immense majorité) n'est pas affectée par ce correctif. */
    public function test_title_is_unchanged_when_no_subtitle_is_present(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('rendez-vous-complicite', 'Rendez-vous Complicité', '26 septembre 2026'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/rendez-vous-complicite/' => Http::response($this->detailHtml()),
        ]);

        $this->artisan('scrape:events')->run();

        $this->assertSame('Rendez-vous Complicité', Event::where('external_ref', 'rendez-vous-complicite')->first()->title);
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (19/09/2026) : le sélecteur ne captait
     * QUE les cartes `--evenements`, ignorant entièrement les vraies pièces
     * de théâtre (`--spectacles`) — vérifié en direct sur theatre-cite.com,
     * 32 pièces jamais importées (contre 36 "événements" bien récupérés).
     */
    public function test_spectacle_cards_are_scraped_alongside_evenements(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('rendez-vous-complicite', 'Rendez-vous Complicité', '26 septembre 2026'),
                $this->card('1-2-3-poquelin', '1, 2, 3 Poquelin', '3 octobre 2026', type: 'spectacles'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/rendez-vous-complicite/' => Http::response($this->detailHtml()),
            'theatre-cite.com/programmation/2026-2027/spectacle/1-2-3-poquelin/' => Http::response($this->detailHtml()),
        ]);

        $this->artisan('scrape:events')->run();

        $this->assertDatabaseHas('events', ['external_ref' => 'rendez-vous-complicite']);
        $this->assertDatabaseHas('events', ['external_ref' => '1-2-3-poquelin', 'title' => '1, 2, 3 Poquelin']);

        $run = ScraperRun::first();
        $this->assertSame(2, $run->items_found);
        $this->assertSame(2, $run->items_created);
    }

    /**
     * Demande client, 24/09/2026 : le Théâtre de la Cité programme dans
     * plusieurs salles distinctes du même bâtiment (constaté en direct le
     * 24/09/2026 sur les vraies fiches "spectacle" : "La Salle", "Le CUB",
     * chacune dans sa PROPRE ligne `.spectacle__informations__content__line`
     * — contrairement au fixture `detailHtml()` ci-dessus qui reproduit le
     * format des fiches "evenement", où tout est mélangé sur une seule
     * ligne). Voir TheatreDeLaCiteDriver::fetchDetail().
     */
    public function test_extracts_room_name_from_real_spectacle_detail_page(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('le-silence', 'Le Silence', '3 octobre 2026', type: 'spectacles'),
            ])),
            'theatre-cite.com/programmation/2026-2027/spectacle/le-silence/' => Http::response(
                '<html><body>'
                .'<div class="spectacle__period">3 novembre 2026</div>'
                .'<div class="spectacle__informations__content__line">Théâtre</div>'
                .'<div class="spectacle__informations__content__line">Théâtre</div>'
                .'<div class="spectacle__informations__content__line">Le CUB Durée 1h45</div>'
                .'<div class="spectacle__informations__content__line">Saison 2026-2027</div>'
                .'</body></html>'
            ),
        ]);

        $this->artisan('scrape:events')->run();

        $event = Event::where('external_ref', 'le-silence')->first();
        $this->assertNotNull($event);
        $this->assertSame('Le CUB', $event->venue_name);
    }

    /**
     * Demande client, 28/09/2026 : la date d'un spectacle vient de la fiche
     * détail (`.spectacle__period`), pas de la carte de listing — seule la
     * fiche détail affiche la vraie plage. Cas réel vérifié en direct :
     * une seule date, sans plage ("8 octobre 2026").
     */
    public function test_single_date_period_sets_the_same_start_and_end_date(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('bord-de-scene', 'Bord de scène', '8 octobre 2026', type: 'evenements'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/bord-de-scene/' => Http::response(
                $this->detailHtml(periodText: '8 octobre 2026', timeText: null)
            ),
        ]);

        $this->artisan('scrape:events')->run();

        $event = Event::where('external_ref', 'bord-de-scene')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-10-08', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-08', $event->end_date->format('Y-m-d'));
    }

    /**
     * Cas réel vérifié en direct : plage dans le MÊME mois, "7 – 8 octobre
     * 2026" (tiret cadratin U+2013, espaces insécables U+00A0) — le DÉBUT
     * ("7") n'a ni mois ni année dans le DOM, empruntés à la fin.
     */
    public function test_same_month_range_borrows_month_and_year_for_the_start_date(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('karaoke', 'Karaoké', '7 octobre 2026', type: 'spectacles'),
            ])),
            'theatre-cite.com/programmation/2026-2027/spectacle/karaoke/' => Http::response(
                $this->detailHtml(periodText: "7\u{a0}–\u{a0}8\u{a0}octobre\u{a0}2026", timeText: null)
            ),
        ]);

        $this->artisan('scrape:events')->run();

        $event = Event::where('external_ref', 'karaoke')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-10-07', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-08', $event->end_date->format('Y-m-d'));
    }

    /**
     * Cas réel vérifié en direct : plage entre deux mois DIFFÉRENTS, "23
     * septembre – 3 octobre 2026" — le DÉBUT a un mois mais pas d'année,
     * empruntée à la fin (sans quoi il retomberait sur l'année du scraper).
     */
    public function test_cross_month_range_borrows_only_the_year_for_the_start_date(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('qui-som', 'Qui som?', '23 septembre 2026', type: 'spectacles'),
            ])),
            'theatre-cite.com/programmation/2026-2027/spectacle/qui-som/' => Http::response(
                $this->detailHtml(periodText: "23\u{a0}septembre –\u{a0}3\u{a0}octobre\u{a0}2026", timeText: null)
            ),
        ]);

        $this->artisan('scrape:events')->run();

        $event = Event::where('external_ref', 'qui-som')->first();
        $this->assertNotNull($event);
        $this->assertSame('2026-09-23', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-03', $event->end_date->format('Y-m-d'));
    }

    /**
     * Demande client, 28/09/2026 : lien de réservation UNIQUEMENT si le bloc
     * `.is-billeterie` existe sur la fiche détail — sinon aucun lien (une
     * "evenement" gratuite comme un bord-de-scène ou une visite du théâtre
     * n'a réellement aucune billetterie, vérifié en direct).
     */
    public function test_no_billeterie_block_means_no_booking_url(): void
    {
        Area::create(['name' => 'TNT Théâtre de la Cité', 'slug' => 'tnt-theatre-de-la-cite']);
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $this->makeSource();

        Http::fake([
            'theatre-cite.com/programmation' => Http::response($this->listingHtml([
                $this->card('cote-coulisses', 'Côté coulisses', '8 octobre 2026'),
            ])),
            'theatre-cite.com/programmation/2026-2027/evenement/cote-coulisses/' => Http::response(
                $this->detailHtml(periodText: '8 octobre 2026', timeText: null, bookingUrl: null)
            ),
        ]);

        $this->artisan('scrape:events')->run();

        $event = Event::where('external_ref', 'cote-coulisses')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->booking_url);
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
