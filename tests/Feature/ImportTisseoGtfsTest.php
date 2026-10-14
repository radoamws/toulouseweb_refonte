<?php

namespace Tests\Feature;

use App\Models\TransportLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ZipArchive;

/**
 * `transport:import-tisseo-gtfs` (brief roadmap "contenu", 14/10/2026,
 * demande client). Flux GTFS réel vérifié en direct avant d'écrire ce code
 * (16 Mo, 1,3M lignes d'horaires — voir docblock de la commande) : les
 * tests utilisent une archive GTFS minimale construite ici, mêmes noms de
 * colonnes/formats que le flux réel, pas le fichier réel (trop volumineux
 * pour la CI).
 */
class ImportTisseoGtfsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un mercredi confirmé (voir CinemaController::cinemaWeekDays(), même semaine de référence utilisée ailleurs dans ce projet). */
    private const REPRESENTATIVE_DATE = '2026-10-14';

    public function test_import_creates_lines_with_summary_data_from_the_representative_wednesday(): void
    {
        Carbon::setTestNow(Carbon::parse(self::REPRESENTATIVE_DATE));
        $this->fakeGtfsDownload($this->buildFixtureZip());

        $this->artisan('transport:import-tisseo-gtfs')->assertSuccessful();

        $metro = TransportLine::where('slug', 'metro-a')->first();
        $this->assertNotNull($metro);
        $this->assertSame('Métro A', $metro->name);
        $this->assertSame('db001b', $metro->color);
        $this->assertSame(['Stop One', 'Stop Two', 'Stop Three'], $metro->stops);
        $this->assertSame('08:00:00', $metro->first_departure);
        $this->assertSame('08:30:00', $metro->last_departure);
        $this->assertSame(30, $metro->frequency_minutes);
        $this->assertSame(2, $metro->trip_count);

        $bus = TransportLine::where('slug', 'bus-13')->first();
        $this->assertNotNull($bus);
        $this->assertSame('Bus 13', $bus->name);
    }

    /** Une ligne SANS aucun trajet le jour représentatif (ligne scolaire/saisonnière) est ignorée, pas créée avec des données vides. */
    public function test_import_skips_routes_with_no_service_on_the_representative_day(): void
    {
        Carbon::setTestNow(Carbon::parse(self::REPRESENTATIVE_DATE));
        $this->fakeGtfsDownload($this->buildFixtureZip(includeSeasonalRouteWithoutTrips: true));

        $this->artisan('transport:import-tisseo-gtfs')->assertSuccessful();

        $this->assertDatabaseMissing(TransportLine::class, ['gtfs_route_id' => 'seasonal']);
    }

    public function test_import_regenerates_the_table_removing_stale_lines(): void
    {
        TransportLine::create(['gtfs_route_id' => 'old', 'name' => 'Ligne disparue', 'slug' => 'ligne-disparue', 'type' => 'bus', 'stops' => []]);

        Carbon::setTestNow(Carbon::parse(self::REPRESENTATIVE_DATE));
        $this->fakeGtfsDownload($this->buildFixtureZip());

        $this->artisan('transport:import-tisseo-gtfs')->assertSuccessful();

        $this->assertDatabaseMissing(TransportLine::class, ['slug' => 'ligne-disparue']);
        $this->assertDatabaseHas(TransportLine::class, ['slug' => 'metro-a']);
    }

    public function test_import_fails_cleanly_when_the_download_fails(): void
    {
        Http::fake(['https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/*' => Http::response(null, 503)]);

        $this->artisan('transport:import-tisseo-gtfs')->assertFailed();

        $this->assertSame(0, TransportLine::count());
    }

    private function fakeGtfsDownload(string $zipPath): void
    {
        Http::fake(['https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/*' => Http::response(file_get_contents($zipPath), 200)]);
    }

    /**
     * Construit une archive GTFS minimale (mêmes en-têtes/formats que le
     * flux Tisséo réel, vérifiés en direct) : 1 ligne métro + 1 ligne bus,
     * chacune avec 2 trajets le mercredi 2026-10-14 (REPRESENTATIVE_DATE),
     * optionnellement 1 ligne "saisonnière" sans aucun trajet ce jour-là.
     */
    private function buildFixtureZip(bool $includeSeasonalRouteWithoutTrips = false): string
    {
        $dir = sys_get_temp_dir().'/gtfs_fixture_'.uniqid();
        mkdir($dir);

        file_put_contents("$dir/routes.txt", implode("\n", [
            'route_id,agency_id,route_short_name,route_long_name,route_color,route_text_color,route_type',
            'line:metroA,network:1,A,Métro A,db001b,ffffff,1',
            'line:bus13,network:1,13,Bus 13,000000,ffffff,3',
            ...($includeSeasonalRouteWithoutTrips ? ['line:seasonal,network:1,99,Ligne saisonnière,000000,ffffff,3'] : []),
        ]));

        file_put_contents("$dir/stops.txt", implode("\n", [
            'stop_id,stop_code,stop_name,stop_lat,stop_lon,location_type,parent_station,platform_code,wheelchair_boarding',
            'sp:1,,Stop One,43.6,1.4,0,,,1',
            'sp:2,,Stop Two,43.61,1.41,0,,,1',
            'sp:3,,Stop Three,43.62,1.42,0,,,1',
            'sp:4,,Stop Bus,43.63,1.43,0,,,1',
        ]));

        file_put_contents("$dir/calendar_dates.txt", implode("\n", [
            'service_id,date,exception_type',
            'wed,20261014,1',
        ]));

        file_put_contents("$dir/trips.txt", implode("\n", [
            'route_id,service_id,trip_id,direction_id,trip_headsign,shape_id',
            'line:metroA,wed,t1,0,Terminus,',
            'line:metroA,wed,t2,1,Retour,',
            'line:bus13,wed,t3,0,Terminus Bus,',
        ]));

        file_put_contents("$dir/stop_times.txt", implode("\n", [
            'trip_id,arrival_time,departure_time,stop_id,pickup_type,drop_off_type,stop_sequence,shape_dist_traveled,timepoint,stop_headsign',
            // Métro A, trajet 1 (3 arrêts, le plus long -> servira de référence pour la liste des arrêts).
            't1,08:00:00,08:00:00,sp:1,0,0,1,0,0,',
            't1,08:05:00,08:05:00,sp:2,0,0,2,0,0,',
            't1,08:10:00,08:10:00,sp:3,0,0,3,0,0,',
            // Métro A, trajet 2 (départ origine à 08:30 -> gap de 30 min avec t1, fréquence attendue = 30).
            't2,08:30:00,08:30:00,sp:1,0,0,1,0,0,',
            't2,08:35:00,08:35:00,sp:2,0,0,2,0,0,',
            // Bus 13, un seul trajet (pas de fréquence calculable avec un seul départ).
            't3,07:00:00,07:00:00,sp:4,0,0,1,0,0,',
        ]));

        $zipPath = "$dir.zip";
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach (['routes.txt', 'stops.txt', 'calendar_dates.txt', 'trips.txt', 'stop_times.txt'] as $file) {
            $zip->addFile("$dir/$file", $file);
        }
        $zip->close();

        return $zipPath;
    }
}
