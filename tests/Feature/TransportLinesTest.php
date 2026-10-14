<?php

namespace Tests\Feature;

use App\Models\TransportLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pages publiques des lignes de transport Tisséo (brief roadmap "contenu",
 * 14/10/2026, demande client). Voir App\Console\Commands\ImportTisseoGtfs
 * pour l'import lui-même (tests séparés, ImportTisseoGtfsTest).
 */
class TransportLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_active_lines_grouped_by_type(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo']]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => ['Capitole']]);
        TransportLine::create(['gtfs_route_id' => 'r3', 'name' => 'Bus Inactif', 'slug' => 'bus-inactif', 'type' => 'bus', 'stops' => [], 'is_active' => false]);

        $response = $this->get('/transports')->assertOk();
        $response->assertSee('Métro A')->assertSee('Bus 13')->assertDontSee('Bus Inactif');
    }

    public function test_show_page_renders_schedule_summary_and_stops(): void
    {
        TransportLine::create([
            'gtfs_route_id' => 'r1', 'name' => 'Métro A', 'long_name' => 'Basso Cambo / Balma-Gramont',
            'slug' => 'metro-a', 'type' => 'metro', 'color' => 'db001b',
            'stops' => ['Basso Cambo', 'Bellefontaine', 'Reynerie'],
            'first_departure' => '05:15:00', 'last_departure' => '24:00:00', 'frequency_minutes' => 2,
        ]);

        $response = $this->get('/transports/metro-a')->assertOk();
        $response->assertSee('Métro A');
        $response->assertSee('Basso Cambo / Balma-Gramont');
        $response->assertSee('05:15');
        // 24:00 (convention GTFS "après minuit") affiché normalisé, pas littéralement "24:00".
        $response->assertSee('00:00');
        $response->assertSee('~2 min');
        $response->assertSee('Bellefontaine')->assertSee('Reynerie');
    }

    public function test_show_page_lists_other_lines_of_the_same_type(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Métro B', 'slug' => 'metro-b', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r3', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => []]);

        $response = $this->get('/transports/metro-a')->assertOk();
        $response->assertSee('Métro B')->assertDontSee('Bus 13');
    }

    public function test_inactive_line_is_not_accessible(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Ligne fermée', 'slug' => 'ligne-fermee', 'type' => 'bus', 'stops' => [], 'is_active' => false]);

        $this->get('/transports/ligne-fermee')->assertNotFound();
    }

    public function test_unknown_line_returns_404(): void
    {
        $this->get('/transports/ceci-n-existe-pas')->assertNotFound();
    }
}
