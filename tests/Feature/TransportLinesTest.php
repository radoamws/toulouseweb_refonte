<?php

namespace Tests\Feature;

use App\Models\TransportLine;
use App\Models\TransportStop;
use App\Models\TransportStopTime;
use App\Models\TransportTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pages publiques des lignes de transport Tisséo (brief roadmap "contenu",
 * 14/10/2026, demande client). Voir App\Console\Commands\ImportTisseoGtfs
 * pour l'import du résumé par ligne (tests séparés, ImportTisseoGtfsTest) et
 * App\Console\Commands\ImportTisseoSchedule pour l'import des horaires par
 * arrêt utilisés par la recherche d'itinéraire (tests séparés,
 * ImportTisseoScheduleTest).
 */
class TransportLinesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Construit un trajet réel (TransportTrip + TransportStopTime), pour les
     * tests de TransportController::journey() — voir son docblock (recherche
     * sur les VRAIES tables stops/trips/stop_times, plus sur
     * `transport_lines.stops`). Les arrêts homonymes (même nom) entre 2
     * appels sont automatiquement le MÊME TransportStop (comportement réel :
     * 2 lignes desservant "Jean Jaurès" partagent le même arrêt).
     *
     * @param  list<string>  $stopNames  dans l'ordre du trajet
     */
    private function createSchedule(string $gtfsRouteId, string $tripId, array $stopNames): void
    {
        $trip = TransportTrip::create(['gtfs_trip_id' => $tripId, 'gtfs_route_id' => $gtfsRouteId]);

        foreach ($stopNames as $sequence => $stopName) {
            $stop = TransportStop::firstOrCreate(['name' => $stopName], ['gtfs_stop_id' => Str::slug($stopName)]);
            TransportStopTime::create([
                'transport_trip_id' => $trip->id,
                'transport_stop_id' => $stop->id,
                'stop_sequence' => $sequence + 1,
                'departure_time' => sprintf('%02d:00:00', 8 + $sequence),
            ]);
        }
    }

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
     * arrêts, un peu comme dans tisseo.fr, et même si tu peux aller plus
     * loin" — trajets directs ET avec 1 correspondance, sur les VRAIS
     * horaires par arrêt (voir docblock de TransportController::journey(),
     * remplace l'ancienne version basée sur `transport_lines.stops`).
     */
    public function test_journey_finds_the_direct_line_between_two_stops(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Bellefontaine', 'Reynerie', 'Capitole']);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Capitole')->assertOk();
        $response->assertSee('Métro A');
        $response->assertSee('3 arrêt(s)');
    }

    public function test_journey_ignores_lines_that_do_not_serve_both_stops(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Capitole']);
        $this->createSchedule('r2', 't2', ['Jean Jaurès', 'Marengo']);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Capitole')->assertOk();
        $response->assertSee('Métro A')->assertDontSee('Bus 13');
    }

    public function test_journey_shows_a_message_when_no_direct_line_exists(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Capitole']);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Gare+Matabiau')->assertOk();
        $response->assertSee('Aucun trajet trouvé')->assertDontSee('Métro A');
    }

    /** Insensible aux accents (ex. "jaures" sans accent doit trouver "Jaurès"). */
    public function test_journey_search_is_accent_insensitive(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Capitole', 'Place Jean Jaurès']);

        $this->get('/transports/itineraire?from=capitole&to=jean+jaures')->assertOk()->assertSee('Bus 13');
    }

    /**
     * Avec de vrais horaires par arrêt, le sens compte réellement (plus de
     * "sens retour" deviné comme avec l'ancienne version sur un seul trajet
     * représentatif) : si le trajet retour n'existe pas dans les données,
     * aucun résultat — s'il existe (2e trajet réel), trouvé normalement.
     */
    public function test_journey_requires_a_real_trip_in_the_queried_direction(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Capitole']);

        $this->get('/transports/itineraire?from=Capitole&to=Basso+Cambo')->assertOk()->assertSee('Aucun trajet trouvé');

        $this->createSchedule('r1', 't2', ['Capitole', 'Basso Cambo']);
        $this->get('/transports/itineraire?from=Capitole&to=Basso+Cambo')->assertOk()->assertSee('Métro A');
    }

    public function test_journey_does_not_match_a_single_stop_against_itself(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Capitole']);

        $response = $this->get('/transports/itineraire?from=Capitole&to=Capitole')->assertOk();
        $response->assertSee('Aucun trajet trouvé');
    }

    public function test_journey_page_shows_the_search_form_without_a_query(): void
    {
        $this->get('/transports/itineraire')->assertOk()->assertSee('Itinéraire entre 2 arrêts');
    }

    /**
     * "Et même si tu peux aller plus loin" (demande client, 16/10/2026) :
     * trajets avec 1 correspondance, voir
     * TransportController::oneTransferConnections().
     */
    public function test_journey_finds_a_connection_with_one_transfer(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => []]);

        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Jean Jaurès']);
        $this->createSchedule('r2', 't2', ['Jean Jaurès', 'Balma Gramont']);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Balma+Gramont')->assertOk();
        $response->assertSee('Avec 1 correspondance');
        $response->assertSee('Métro A')->assertSee('Bus 13')->assertSee('Jean Jaurès');
    }

    /** Un changement sur la MÊME ligne des 2 côtés n'est pas une vraie correspondance. */
    public function test_journey_does_not_suggest_a_transfer_using_the_same_line_twice(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Jean Jaurès']);
        $this->createSchedule('r1', 't2', ['Jean Jaurès', 'Balma Gramont']);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Balma+Gramont')->assertOk();
        $response->assertDontSee('Avec 1 correspondance');
        $response->assertSee('Aucun trajet trouvé');
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (16/10/2026, en vérifiant contre les
     * vraies données Tisséo) : "Université Paul Sabatier" (pôle Téléo/Métro
     * B) a 16 `stop_id` GTFS DIFFÉRENTS partageant ce même nom (1 par
     * quai/ligne) — un groupement par `stop_id` exact (1re version) ne
     * trouvait PRESQUE JAMAIS de correspondance entre 2 modes différents,
     * puisque chaque mode arrive sur un quai différent. Voir docblock de
     * TransportController::oneTransferConnections() (désormais regroupé
     * par NOM d'arrêt, pas par id).
     */
    public function test_journey_finds_a_transfer_across_different_platforms_sharing_the_same_stop_name(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Téléo', 'slug' => 'teleo', 'type' => 'teleo', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Métro B', 'slug' => 'metro-b', 'type' => 'metro', 'stops' => []]);

        // 2 TransportStop DIFFÉRENTS (2 quais réels) partageant le même nom.
        $platformA = TransportStop::create(['gtfs_stop_id' => 'sp:a', 'name' => 'Université Paul Sabatier']);
        $platformB = TransportStop::create(['gtfs_stop_id' => 'sp:b', 'name' => 'Université Paul Sabatier']);
        $origin = TransportStop::create(['gtfs_stop_id' => 'sp:origin', 'name' => 'Oncopole']);
        $destination = TransportStop::create(['gtfs_stop_id' => 'sp:dest', 'name' => 'Borderouge']);

        $trip1 = TransportTrip::create(['gtfs_trip_id' => 't1', 'gtfs_route_id' => 'r1']);
        TransportStopTime::create(['transport_trip_id' => $trip1->id, 'transport_stop_id' => $origin->id, 'stop_sequence' => 1, 'departure_time' => '08:00:00']);
        TransportStopTime::create(['transport_trip_id' => $trip1->id, 'transport_stop_id' => $platformA->id, 'stop_sequence' => 2, 'departure_time' => '08:10:00']);

        $trip2 = TransportTrip::create(['gtfs_trip_id' => 't2', 'gtfs_route_id' => 'r2']);
        TransportStopTime::create(['transport_trip_id' => $trip2->id, 'transport_stop_id' => $platformB->id, 'stop_sequence' => 1, 'departure_time' => '08:20:00']);
        TransportStopTime::create(['transport_trip_id' => $trip2->id, 'transport_stop_id' => $destination->id, 'stop_sequence' => 2, 'departure_time' => '08:30:00']);

        $response = $this->get('/transports/itineraire?from=Oncopole&to=Borderouge')->assertOk();
        $response->assertSee('Avec 1 correspondance');
        $response->assertSee('Téléo')->assertSee('Métro B')->assertSee('Université Paul Sabatier');
    }

    /** Un trajet direct existant est préféré/affiché même si une correspondance existerait aussi. */
    public function test_journey_shows_both_direct_and_transfer_sections_when_both_exist(): void
    {
        TransportLine::create(['gtfs_route_id' => 'r1', 'name' => 'Métro A', 'slug' => 'metro-a', 'type' => 'metro', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r2', 'name' => 'Bus 13', 'slug' => 'bus-13', 'type' => 'bus', 'stops' => []]);
        TransportLine::create(['gtfs_route_id' => 'r3', 'name' => 'Tram T1', 'slug' => 'tram-t1', 'type' => 'tram', 'stops' => []]);

        // Direct (métro A).
        $this->createSchedule('r1', 't1', ['Basso Cambo', 'Balma Gramont']);
        // Avec 1 correspondance (bus 13 -> Jean Jaurès -> tram T1).
        $this->createSchedule('r2', 't2', ['Basso Cambo', 'Jean Jaurès']);
        $this->createSchedule('r3', 't3', ['Jean Jaurès', 'Balma Gramont']);

        $response = $this->get('/transports/itineraire?from=Basso+Cambo&to=Balma+Gramont')->assertOk();
        $response->assertSee('Trajets directs')->assertSee('Métro A');
        $response->assertSee('Avec 1 correspondance')->assertSee('Bus 13')->assertSee('Tram T1');
    }
}
