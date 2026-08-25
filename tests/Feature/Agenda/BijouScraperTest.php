<?php

namespace Tests\Feature\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\BijouDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper agenda pour Le Bijou (le-bijou.soticket.net) — reconstruit depuis
 * le VRAI code legacy `updateAgendaforBijou` (voir docblock de BijouDriver
 * pour le contexte du jeton Bearer). Vérifié en direct le 25/08/2026 :
 * 39/39 spectacles importés avec le jeton legacy (toujours valide).
 */
class BijouScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_event_from_real_soticket_shape(): void
    {
        Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou', 'legacy_id' => 10]);
        EventCategory::create(['name' => 'Spectacles', 'slug' => 'spectacles', 'legacy_id' => 7]);

        $source = ScraperSource::create([
            'name' => 'Le Bijou',
            'type' => 'agenda',
            'driver_class' => BijouDriver::class,
            'config' => ['area_slug' => 'le-bijou'],
            'is_active' => true,
        ]);

        Http::fake([
            'le-bijou.soticket.net/api/v2/shows*' => Http::response([
                'data' => [[
                    'slug' => 'le-plus-grand-cabaret',
                    'edito' => ['title' => 'Nom du spectacle - Sous titre - Une description complète'],
                    'sessions' => [[
                        'range' => ['title' => '15/20/25'],
                        'start_date' => 1790000000,
                        'time_zone' => 'Europe/Paris',
                    ]],
                    'picture' => ['src' => 'https://le-bijou.soticket.net/img/show.jpg'],
                    'start_date' => 1790000000,
                    'end_date' => 0,
                ]],
            ]),
        ]);

        $exitCode = $this->artisan('scrape:events', ['--source' => $source->id])->run();

        $this->assertSame(0, $exitCode);

        $event = Event::where('external_ref', 'le-plus-grand-cabaret')->first();
        $this->assertNotNull($event);
        $this->assertSame('Nom du spectacle - Sous titre - Une description complète', $event->title);
        $this->assertSame('Sous titre', $event->subtitle);
        $this->assertSame('Une description complète', $event->description);
        $this->assertSame('15€ - 20€ - 25€', $event->price);
        $this->assertTrue($event->categories->contains('slug', 'spectacles'));

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
    }

    public function test_authorization_header_uses_configured_bearer_token(): void
    {
        Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou', 'legacy_id' => 10]);
        EventCategory::create(['name' => 'Spectacles', 'slug' => 'spectacles', 'legacy_id' => 7]);

        $source = ScraperSource::create([
            'name' => 'Le Bijou',
            'type' => 'agenda',
            'driver_class' => BijouDriver::class,
            'config' => ['area_slug' => 'le-bijou', 'bearer_token' => 'jeton-de-test'],
            'is_active' => true,
        ]);

        Http::fake(['le-bijou.soticket.net/*' => Http::response(['data' => []])]);

        $this->artisan('scrape:events', ['--source' => $source->id])->run();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer jeton-de-test'));
    }
}
