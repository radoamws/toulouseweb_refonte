<?php

namespace Tests\Feature;

use App\Models\TransportLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carrousel transports de la home (brief roadmap "contenu", 14/10/2026,
 * demande client : "ajoute un menu transport et crée un carousel... le
 * clic sur ce carousel renvoi vers /transports"). Voir
 * x-site.transport-carousel et HomeController::index().
 */
class TransportCarouselTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_shows_a_slide_per_mode_with_real_line_counts(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Métro B', 'slug' => 'metro-b', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r3', 'name' => 'Téléo', 'slug' => 'teleo', 'type' => 'teleo', 'stops' => []]);

        $response = $this->get('/')->assertOk();
        $response->assertSee('Métro');
        $response->assertSee('2 lignes');
        $response->assertSee('Téléo');
        $response->assertSee('1 ligne');
        // Tramway/Bus n'ont aucune ligne importée dans ce scénario : pas de slide pour un mode vide.
        $response->assertDontSee('Tramway');
        $response->assertDontSee('Bus Tisséo');
    }

    public function test_homepage_hides_the_carousel_entirely_when_no_line_has_been_imported_yet(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertDontSee('aria-label="Transports en commun à Toulouse"', false);
    }

    public function test_carousel_slides_link_to_the_transports_index_page(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => []]);

        $response = $this->get('/')->assertOk();
        $response->assertSee('href="/transports"', false);
    }

    public function test_main_navigation_includes_a_transports_link(): void
    {
        $this->get('/')->assertOk()->assertSee('href="/transports"', false);
    }
}
