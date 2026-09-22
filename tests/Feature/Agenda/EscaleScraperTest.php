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
 * Scraper agenda pour L'Escale (Tournefeuille) — plateforme Ardei-Soft/VEL,
 * voir docblock de AbstractArdeiSoftDriver pour le contexte (à tort jugée
 * "trop obfusquée" lors d'une investigation live précédente — le VRAI code
 * legacy `updateAgendaforEscale` prouve le contraire). Fixture JSON conforme
 * à la vraie forme de réponse, vérifiée en direct le 25/08/2026 (72/72
 * spectacles importés sans erreur).
 */
class EscaleScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_event_from_real_ardeisoft_shape(): void
    {
        Area::create(['name' => "L'Escale", 'slug' => 'lescale-2', 'legacy_id' => 3563]);
        EventCategory::create(['name' => 'Spectacles', 'slug' => 'spectacles', 'legacy_id' => 7]);

        $source = ScraperSource::create([
            'name' => "L'Escale (Tournefeuille)",
            'type' => 'agenda',
            'driver_class' => EscaleDriver::class,
            'config' => ['town_slug' => 'tournefeuille', 'area_slug' => 'lescale-2', 'tarifs_group' => 3],
            'is_active' => true,
        ]);

        Http::fake([
            'www.ardei-soft.com/tournefeuille/SenousritPGI*' => Http::response([
                'spectacles' => [[
                    's' => 'le-grand-cirque',
                    'compagnie' => 'Compagnie du Grand Soir',
                    'txt' => 'Un spectacle familial.',
                    'prixMin' => 8,
                    'prixMax' => 15,
                    'fmm1' => 'le-grand-cirque.jpg',
                    'dateD' => [2026, 10, 12, 20, 30],
                    'dateF' => [2026, 10, 12, 22, 0],
                ]],
            ]),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'le-grand-cirque')->first();
        $this->assertNotNull($event);
        $this->assertSame('le-grand-cirque', $event->title);
        $this->assertSame('de 8 € à 15 €', $event->price);
        $this->assertSame('https://www.ardei-soft.com/tournefeuille/img/le-grand-cirque.jpg', $event->image);
        $this->assertSame('2026-10-12 20:30:00', $event->start_date->format('Y-m-d H:i:s'));
        // Horaire (06/09/2026, corrigé suite à l'audit §18) : "à partir de {heure de FIN}",
        // reproduit tel quel du legacy (dateF, pas dateD — une bizarrerie du code source).
        $this->assertSame(['à partir de 22:0'], $event->schedule);
        $this->assertTrue($event->categories->contains('slug', 'spectacles'));

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_created);
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (22/09/2026, capture client) : `txt`
     * renvoyé par l'API VEL est du HTML brut — stocké tel quel jusqu'ici,
     * les balises s'affichaient littéralement en texte visible côté public
     * ("<div><br></div>..."). Voir AbstractArdeiSoftDriver::cleanRichText().
     */
    public function test_html_in_txt_field_is_cleaned_to_plain_text(): void
    {
        Area::create(['name' => "L'Escale", 'slug' => 'lescale-2', 'legacy_id' => 3563]);
        EventCategory::create(['name' => 'Spectacles', 'slug' => 'spectacles', 'legacy_id' => 7]);

        $source = ScraperSource::create([
            'name' => "L'Escale (Tournefeuille)",
            'type' => 'agenda',
            'driver_class' => EscaleDriver::class,
            'config' => ['town_slug' => 'tournefeuille', 'area_slug' => 'lescale-2', 'tarifs_group' => 3],
            'is_active' => true,
        ]);

        Http::fake([
            'www.ardei-soft.com/tournefeuille/SenousritPGI*' => Http::response([
                'spectacles' => [[
                    's' => 'camera-obscura',
                    'txt' => '<div><br></div><div><b>Tarifs : D</b></div><div><b>tarif plein</b> 16&euro; </div>',
                    'fmm1' => 'camera-obscura.jpg',
                    'dateD' => [2026, 10, 3, 14, 30],
                ]],
            ]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $event = Event::where('external_ref', 'camera-obscura')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('<div>', $event->description);
        $this->assertStringNotContainsString('<b>', $event->description);
        $this->assertStringContainsString('Tarifs : D', $event->description);
        $this->assertStringContainsString('tarif plein 16€', $event->description);
    }

    public function test_entries_without_visual_are_skipped(): void
    {
        Area::create(['name' => "L'Escale", 'slug' => 'lescale-2', 'legacy_id' => 3563]);
        EventCategory::create(['name' => 'Spectacles', 'slug' => 'spectacles', 'legacy_id' => 7]);

        $source = ScraperSource::create([
            'name' => "L'Escale (Tournefeuille)",
            'type' => 'agenda',
            'driver_class' => EscaleDriver::class,
            'config' => ['town_slug' => 'tournefeuille', 'area_slug' => 'lescale-2'],
            'is_active' => true,
        ]);

        Http::fake([
            'www.ardei-soft.com/tournefeuille/SenousritPGI*' => Http::response([
                'spectacles' => [['s' => 'ligne-technique-sans-visuel']],
            ]),
        ]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, Event::count());
    }
}
