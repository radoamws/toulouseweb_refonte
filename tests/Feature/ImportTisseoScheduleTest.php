<?php

namespace Tests\Feature;

use App\Models\TransportStop;
use App\Models\TransportStopTime;
use App\Models\TransportTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * `transport:import-tisseo-schedule` (brief roadmap "contenu", 16/10/2026 —
 * itinéraire avec correspondance). Voir docblock de la commande pour
 * pourquoi elle est VOLONTAIREMENT séparée de `transport:import-tisseo-gtfs`
 * (tests dans ImportTisseoGtfsTest) — même flux GTFS réel, même journée
 * représentative, mais persiste les horaires détaillés par arrêt plutôt
 * qu'un résumé par ligne.
 */
class ImportTisseoScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Même mercredi de référence que ImportTisseoGtfsTest. */
    private const REPRESENTATIVE_DATE = '2026-10-14';

    public function test_import_persists_stops_trips_and_stop_times_for_the_representative_wednesday(): void
    {
        Carbon::setTestNow(Carbon::parse(self::REPRESENTATIVE_DATE));
        $this->fakeGtfsDownload($this->buildFixtureZip());

        $this->artisan('transport:import-tisseo-schedule')->assertSuccessful();

        $this->assertSame(4, TransportStop::count());
        $this->assertSame(2, TransportTrip::count());

        $stopOne = TransportStop::where('name', 'Stop One')->first();
        $this->assertNotNull($stopOne);
        $this->assertEqualsWithDelta(43.6, (float) $stopOne->lat, 0.0001);

        $trip = TransportTrip::where('gtfs_trip_id', 't1')->first();
        $this->assertSame('line:metroA', $trip->gtfs_route_id);
        $this->assertSame(3, TransportStopTime::where('transport_trip_id', $trip->id)->count());

        $firstStopTime = TransportStopTime::where('transport_trip_id', $trip->id)->where('stop_sequence', 1)->first();
        $this->assertSame($stopOne->id, $firstStopTime->transport_stop_id);
        $this->assertSame('08:00:00', $firstStopTime->departure_time);
    }

    /** Trajet hors journée représentative (service_id sans correspondance dans calendar_dates.txt pour ce jour) : ignoré. */
    public function test_import_only_keeps_trips_running_on_the_representative_day(): void
    {
        Carbon::setTestNow(Carbon::parse(self::REPRESENTATIVE_DATE));
        $this->fakeGtfsDownload($this->buildFixtureZip(includeOffDayTrip: true));

        $this->artisan('transport:import-tisseo-schedule')->assertSuccessful();

        $this->assertDatabaseMissing(TransportTrip::class, ['gtfs_trip_id' => 'off-day']);
    }

    public function test_import_regenerates_everything_removing_stale_data(): void
    {
        $staleStop = TransportStop::create(['gtfs_stop_id' => 'stale', 'name' => 'Arrêt disparu']);
        $staleTrip = TransportTrip::create(['gtfs_trip_id' => 'stale-trip', 'gtfs_route_id' => 'stale-route']);
        TransportStopTime::create(['transport_trip_id' => $staleTrip->id, 'transport_stop_id' => $staleStop->id, 'stop_sequence' => 1, 'departure_time' => '00:00:00']);

        Carbon::setTestNow(Carbon::parse(self::REPRESENTATIVE_DATE));
        $this->fakeGtfsDownload($this->buildFixtureZip());

        $this->artisan('transport:import-tisseo-schedule')->assertSuccessful();

        $this->assertDatabaseMissing(TransportStop::class, ['gtfs_stop_id' => 'stale']);
        $this->assertDatabaseMissing(TransportTrip::class, ['gtfs_trip_id' => 'stale-trip']);
    }

    public function test_import_fails_cleanly_when_the_download_fails(): void
    {
        Http::fake(['https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/*' => Http::response(null, 503)]);

        $this->artisan('transport:import-tisseo-schedule')->assertFailed();

        $this->assertSame(0, TransportStop::count());
    }

    private function fakeGtfsDownload(string $zipPath): void
    {
        Http::fake(['https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/*' => Http::response(file_get_contents($zipPath), 200)]);
    }

    /**
     * Même fixture que ImportTisseoGtfsTest::buildFixtureZip() (1 ligne
     * métro, 2 trajets le mercredi 2026-10-14), avec en plus un trajet
     * "hors jour" optionnel pour vérifier le filtrage par journée
     * représentative.
     */
    private function buildFixtureZip(bool $includeOffDayTrip = false): string
    {
        $dir = sys_get_temp_dir().'/gtfs_schedule_fixture_'.uniqid();
        mkdir($dir);

        file_put_contents("$dir/routes.txt", implode("\n", [
            'route_id,agency_id,route_short_name,route_long_name,route_color,route_text_color,route_type',
            'line:metroA,network:1,A,Métro A,db001b,ffffff,1',
        ]));

        file_put_contents("$dir/stops.txt", implode("\n", [
            'stop_id,stop_code,stop_name,stop_lat,stop_lon,location_type,parent_station,platform_code,wheelchair_boarding',
            'sp:1,,Stop One,43.6,1.4,0,,,1',
            'sp:2,,Stop Two,43.61,1.41,0,,,1',
            'sp:3,,Stop Three,43.62,1.42,0,,,1',
            'sp:4,,Stop Four,43.63,1.43,0,,,1',
        ]));

        file_put_contents("$dir/calendar_dates.txt", implode("\n", [
            'service_id,date,exception_type',
            'wed,20261014,1',
            'other_day,20261015,1',
        ]));

        file_put_contents("$dir/trips.txt", implode("\n", [
            'route_id,service_id,trip_id,direction_id,trip_headsign,shape_id',
            'line:metroA,wed,t1,0,Terminus,',
            'line:metroA,wed,t2,1,Retour,',
            ...($includeOffDayTrip ? ['line:metroA,other_day,off-day,0,Hors jour,'] : []),
        ]));

        file_put_contents("$dir/stop_times.txt", implode("\n", [
            'trip_id,arrival_time,departure_time,stop_id,pickup_type,drop_off_type,stop_sequence,shape_dist_traveled,timepoint,stop_headsign',
            't1,08:00:00,08:00:00,sp:1,0,0,1,0,0,',
            't1,08:05:00,08:05:00,sp:2,0,0,2,0,0,',
            't1,08:10:00,08:10:00,sp:3,0,0,3,0,0,',
            't2,08:30:00,08:30:00,sp:1,0,0,1,0,0,',
            't2,08:35:00,08:35:00,sp:2,0,0,2,0,0,',
            ...($includeOffDayTrip ? ['off-day,07:00:00,07:00:00,sp:4,0,0,1,0,0,'] : []),
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
