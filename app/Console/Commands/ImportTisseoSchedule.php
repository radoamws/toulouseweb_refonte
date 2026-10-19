<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Importe les horaires détaillés par arrêt (stops/trips/stop_times) de la
 * journée représentative Tisséo (brief roadmap "contenu", 16/10/2026 —
 * recherche d'itinéraire avec correspondance, voir
 * TransportController::journey()), en complément de `transport_lines`
 * (résumé par ligne, voir ImportTisseoGtfs, inchangée).
 *
 * ⚠️ Commande ET déclenchement WebCron VOLONTAIREMENT séparés de
 * `transport:import-tisseo-gtfs` : benchmarké en réel le 16/10/2026 avant
 * d'écrire ce code (236 049 lignes stop_times pour ~10 800 trajets un
 * mercredi type, ~30s rien que pour l'insertion en lots, même sur un poste
 * de dev). Empiler ça dans LA MÊME requête HTTP que `RunWebCron` (déjà :
 * sitemap + scraping cinéma + ~15 sources agenda + dédoublonnage, tout en
 * un seul appel synchrone) ferait courir un risque réel de dépasser la
 * limite d'exécution PHP-FPM du serveur web mutualisé. Voir
 * App\Http\Controllers\WebCronTransportController (route
 * `/webcron-transport/{token}`, jeton séparé) — nécessite un 2e WebCron à
 * paramétrer dans le manager Infomaniak, à un horaire différent du WebCron
 * principal (voir README.md).
 *
 * Même flux GTFS et même journée représentative (premier mercredi à partir
 * d'aujourd'hui) que ImportTisseoGtfs — volontairement RE-téléchargés et
 * RE-calculés ici plutôt que mutualisés : les 2 commandes sont déclenchées
 * par 2 requêtes HTTP indépendantes, à des horaires différents, rien ne
 * garantit qu'elles s'exécutent dans le même process. Le lien avec
 * `transport_lines` (pour afficher la ligne/couleur d'un trajet) se fait
 * uniquement en LECTURE, au moment de la recherche, via `gtfs_route_id` —
 * jamais au moment de l'import (voir migration de `transport_trips`).
 */
class ImportTisseoSchedule extends Command
{
    protected $signature = 'transport:import-tisseo-schedule';

    protected $description = "Réimporte les horaires détaillés par arrêt (stops/trips/stop_times) de la journée représentative Tisséo";

    private const GTFS_URL = 'https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/files/fc1dda89077cf37e4f7521760e0ef4e9/download/';

    private const INSERT_CHUNK = 2000;

    public function handle(): int
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'tisseo_schedule_');
        $extractPath = sys_get_temp_dir().'/tisseo_schedule_'.Str::random(8);

        try {
            $this->info('Téléchargement du flux GTFS Tisséo...');
            $response = Http::timeout(120)->get(self::GTFS_URL);

            if (! $response->successful()) {
                $this->error("Échec du téléchargement : HTTP {$response->status()}");

                return self::FAILURE;
            }

            file_put_contents($zipPath, $response->body());

            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                $this->error('Archive GTFS invalide ou illisible.');

                return self::FAILURE;
            }
            $zip->extractTo($extractPath);
            $zip->close();

            $date = $this->representativeDate()->format('Ymd');
            $serviceIds = $this->serviceIdsForDate("$extractPath/calendar_dates.txt", $date);
            $trips = $this->tripsForServices("$extractPath/trips.txt", $serviceIds);

            $count = 0;
            DB::transaction(function () use ($extractPath, $trips, &$count) {
                // Régénéré entièrement à chaque import (même principe que
                // transport_lines, voir sa migration) : pas d'historique de
                // trajets/horaires à conserver. Transaction englobante :
                // un échec en cours de route laisse les données du run
                // précédent intactes plutôt qu'un état à moitié vidé.
                DB::table('transport_stop_times')->delete();
                DB::table('transport_trips')->delete();
                DB::table('transport_stops')->delete();

                $stopIds = $this->importStops("$extractPath/stops.txt");
                $tripIds = $this->importTrips($trips);
                $count = $this->importStopTimes("$extractPath/stop_times.txt", $trips, $tripIds, $stopIds);
            });

            $this->info(count($trips)." trajet(s) et $count ligne(s) d'horaires importés (sur ".$this->representativeDate()->translatedFormat('l d F Y').').');

            return self::SUCCESS;
        } finally {
            @unlink($zipPath);
            $this->deleteDirectory($extractPath);
        }
    }

    /** Premier mercredi à partir d'aujourd'hui inclus — IDENTIQUE à ImportTisseoGtfs::representativeDate(), volontairement dupliqué (voir docblock de la classe : les 2 imports sont indépendants, pas d'état partagé). */
    private function representativeDate(): Carbon
    {
        $date = now()->startOfDay();
        while ($date->dayOfWeek !== Carbon::WEDNESDAY) {
            $date = $date->addDay();
        }

        return $date;
    }

    /** @return array<string, true> service_id => true */
    private function serviceIdsForDate(string $path, string $date): array
    {
        $serviceIds = [];
        foreach ($this->readCsv($path) as $row) {
            if ($row['date'] === $date && $row['exception_type'] === '1') {
                $serviceIds[$row['service_id']] = true;
            }
        }

        return $serviceIds;
    }

    /** @param array<string, true> $serviceIds @return array<string, string> trip_id => route_id */
    private function tripsForServices(string $path, array $serviceIds): array
    {
        $trips = [];
        foreach ($this->readCsv($path) as $row) {
            if (isset($serviceIds[$row['service_id']])) {
                $trips[$row['trip_id']] = $row['route_id'];
            }
        }

        return $trips;
    }

    /** @return array<string, int> gtfs_stop_id => id */
    private function importStops(string $path): array
    {
        $now = now();
        foreach (array_chunk(iterator_to_array($this->readCsv($path)), self::INSERT_CHUNK) as $chunk) {
            DB::table('transport_stops')->insert(array_map(fn ($row) => [
                'gtfs_stop_id' => $row['stop_id'],
                'name' => $row['stop_name'],
                'lat' => $row['stop_lat'] !== '' ? $row['stop_lat'] : null,
                'lon' => $row['stop_lon'] !== '' ? $row['stop_lon'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return DB::table('transport_stops')->pluck('id', 'gtfs_stop_id')->all();
    }

    /** @param array<string, string> $trips trip_id => route_id @return array<string, int> gtfs_trip_id => id */
    private function importTrips(array $trips): array
    {
        $now = now();
        foreach (array_chunk(array_keys($trips), self::INSERT_CHUNK) as $chunk) {
            DB::table('transport_trips')->insert(array_map(fn ($tripId) => [
                'gtfs_trip_id' => $tripId,
                'gtfs_route_id' => $trips[$tripId],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return DB::table('transport_trips')->pluck('id', 'gtfs_trip_id')->all();
    }

    /**
     * Un seul passage sur stop_times.txt, filtré à la journée représentative
     * (même principe que ImportTisseoGtfs::scanStopTimes()), inséré en lots.
     *
     * @param  array<string, string>  $trips trip_id => route_id (sert de filtre)
     * @param  array<string, int>  $tripIds gtfs_trip_id => id
     * @param  array<string, int>  $stopIds gtfs_stop_id => id
     */
    private function importStopTimes(string $path, array $trips, array $tripIds, array $stopIds): int
    {
        $count = 0;
        $buffer = [];

        foreach ($this->readCsv($path) as $row) {
            if (! isset($trips[$row['trip_id']])) {
                continue; // hors journée représentative
            }

            $tripId = $tripIds[$row['trip_id']] ?? null;
            $stopId = $stopIds[$row['stop_id']] ?? null;
            if ($tripId === null || $stopId === null) {
                continue; // référence incohérente (trip/stop absent du flux) — ignorée plutôt que de planter.
            }

            $buffer[] = [
                'transport_trip_id' => $tripId,
                'transport_stop_id' => $stopId,
                'stop_sequence' => (int) $row['stop_sequence'],
                'departure_time' => $row['departure_time'],
            ];
            $count++;

            if (count($buffer) >= self::INSERT_CHUNK) {
                DB::table('transport_stop_times')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('transport_stop_times')->insert($buffer);
        }

        return $count;
    }

    /**
     * Lecture ligne à ligne (jamais `file()`/`file_get_contents()` — les
     * fichiers GTFS les plus gros font plusieurs dizaines de Mo) : mémoire
     * bornée quel que soit le nombre de lignes du fichier source. Identique
     * à ImportTisseoGtfs::readCsv().
     *
     * @return \Generator<int, array<string, string>>
     */
    private function readCsv(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            yield array_combine($header, $row);
        }

        fclose($handle);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
