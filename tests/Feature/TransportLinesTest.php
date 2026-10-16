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

    /**
     * Demande client (14/10/2026) : "ajoute un filtre/recherche par Arrêts
     * desservis" — `stops` est un JSON par ligne (pas de table séparée),
     * filtré en PHP (voir docblock de TransportController::index()).
     */
    public function test_search_finds_lines_serving_a_matching_stop(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo', 'Capitole']]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => ['Jean Jaurès']]);
        TransportLine::create(['gtfs_route_id' => 'r3', 'name' => 'Bus 20', 'slug' => 'bus-20', 'type' => 'bus', 'stops' => ['Basso Cambo']]);

        $response = $this->get('/transports?q=Capitole')->assertOk();
        $response->assertSee('Métro A')->assertDontSee('Bus 13')->assertDontSee('Bus 20');
    }

    /** Insensible à la casse et aux correspondances partielles (ex. "jean" doit trouver "Jean Jaurès"). */
    public function test_search_is_case_insensitive_and_matches_partial_stop_names(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => ['Place Jean Jaurès']]);

        $this->get('/transports?q=jean')->assertOk()->assertSee('Bus 13');
        $this->get('/transports?q=JEAN JAURÈS')->assertOk()->assertSee('Bus 13');
    }

    public function test_search_shows_a_message_when_no_line_serves_the_requested_stop(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo']]);

        $response = $this->get('/transports?q=Gare+Matabiau')->assertOk();
        $response->assertSee('Aucune ligne ne dessert un arrêt correspondant')->assertDontSee('Métro A');
    }

    public function test_search_matches_lines_across_several_types(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Jean Jaurès']]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => ['Jean Jaurès']]);

        $response = $this->get('/transports?q=Jean Jaurès')->assertOk();
        $response->assertSee('Métro A')->assertSee('Bus 13');
    }

    /**
     * Demande client (16/10/2026) : "ajoute la recherche des bus entre 2
     * arrêts, un peu comme dans tisseo.fr" — limitée aux trajets DIRECTS
     * (une seule ligne, pas de correspondance), voir docblock de
     * TransportController::journey().
     */
    public function test_journey_finds_the_direct_line_between_two_stops(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo', 'Bellefontaine', 'Reynerie', 'Capitole']]);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Capitole')->assertOk();
        $response->assertSee('Métro A');
        $response->assertSee('3 arrêt(s)');
    }

    public function test_journey_ignores_lines_that_do_not_serve_both_stops(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo', 'Capitole']]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => ['Jean Jaurès', 'Marengo']]);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Capitole')->assertOk();
        $response->assertSee('Métro A')->assertDontSee('Bus 13');
    }

    public function test_journey_shows_a_message_when_no_direct_line_exists(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo', 'Capitole']]);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Gare+Matabiau')->assertOk();
        $response->assertSee('Aucune ligne ne relie directement')->assertDontSee('Métro A');
    }

    /** Insensible aux accents (ex. "jaures" sans accent doit trouver "Jaurès"). */
    public function test_journey_search_is_accent_insensitive(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => ['Capitole', 'Place Jean Jaurès']]);

        $this->get('/transports/itineraire?from=capitole&to=jean+jaures')->assertOk()->assertSee('Bus 13');
    }

    public function test_journey_flags_the_reversed_direction(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo', 'Capitole']]);

        $response = $this->get('/transports/itineraire?from=Capitole&to=Basso+Cambo')->assertOk();
        $response->assertSee('Métro A')->assertSee('sens retour');
    }

    public function test_journey_does_not_match_a_single_stop_against_itself(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => ['Basso Cambo', 'Capitole']]);

        $response = $this->get('/transports/itineraire?from=Capitole&to=Capitole')->assertOk();
        $response->assertSee('Aucune ligne ne relie directement');
    }

    public function test_journey_page_shows_the_search_form_without_a_query(): void
    {
        $this->get('/transports/itineraire')->assertOk()->assertSee('Itinéraire entre 2 arrêts');
    }
}
