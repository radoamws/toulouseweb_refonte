<?php

namespace App\Console\Commands;

use App\Models\TransportLine;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Importe un RÉSUMÉ par ligne Tisséo (brief roadmap "contenu", 14/10/2026,
 * demande client) depuis l'open data GTFS officiel de Toulouse Métropole
 * (licence ODbL, republié chaque jour à 4h15, fenêtre glissante de 3-4
 * semaines — voir data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs).
 *
 * ⚠️ Choix d'architecture délibéré, vérifié sur le flux réel avant
 * d'écrire ce code (16 Mo, 1,3 MILLION de lignes dans `stop_times.txt` pour
 * 55 329 trajets sur la fenêtre complète) : répliquer fidèlement tout le
 * flux GTFS en base, réimporté chaque jour pour suivre la fenêtre glissante,
 * serait un vrai chantier d'ETL récurrent (volumétrie + traitement
 * quotidien) hors de proportion avec la demande ("contenu utilitaire à
 * très faible effort"). Choix retenu à la place : ne garder qu'un petit
 * nombre de métriques DISTILLÉES par ligne (premier/dernier départ,
 * fréquence indicative, arrêts desservis), calculées une fois par import à
 * partir d'une SEULE journée représentative (le premier mercredi à partir
 * d'aujourd'hui — un mercredi "normal" est le jour le plus représentatif
 * d'un service hors vacances/week-end). Résultat : ~12 700 trajets à
 * traiter au lieu de 55 000+, et la table `transport_lines` ne contient
 * jamais plus de ~123 lignes (une par ligne Tisséo réelle), pas un
 * historique d'horaires brut.
 *
 * Pas de temps réel ici (choix client explicite, "GTFS statique" — le
 * temps réel nécessiterait l'API GTFS-RT/une clé Tisséo, hors scope).
 */
class ImportTisseoGtfs extends Command
{
    protected $signature = 'transport:import-tisseo-gtfs';

    protected $description = "Régénère les lignes Tisséo (résumé par ligne) depuis l'open data GTFS de Toulouse Métropole";

    private const GTFS_URL = 'https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/files/fc1dda89077cf37e4f7521760e0ef4e9/download/';

    /** GTFS `route_type` -> notre `type` (seuls ceux réellement présents dans le flux Tisséo, vérifié). */
    private const ROUTE_TYPES = ['0' => 'tram', '1' => 'metro', '3' => 'bus', '6' => 'teleo'];

    public function handle(): int
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'tisseo_gtfs_');
        $extractPath = sys_get_temp_dir().'/tisseo_gtfs_'.Str::random(8);

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

            $lines = $this->buildLines($extractPath);

            // Table entièrement régénérée à chaque import (voir docblock de
            // la migration) — pas d'historique de lignes à conserver.
            TransportLine::query()->delete();
            foreach ($lines as $line) {
                TransportLine::create($line);
            }

            $this->info(count($lines).' ligne(s) importée(s) (sur '.$this->representativeDate()->translatedFormat('l d F Y').').');

            return self::SUCCESS;
        } finally {
            @unlink($zipPath);
            $this->deleteDirectory($extractPath);
        }
    }

    /** @return list<array<string, mixed>> */
    private function buildLines(string $dir): array
    {
        $routes = $this->indexBy("$dir/routes.txt", 'route_id');
        $stopNames = array_map(fn ($row) => $row['stop_name'], $this->indexBy("$dir/stops.txt", 'stop_id'));

        $date = $this->representativeDate()->format('Ymd');
        $serviceIds = $this->serviceIdsForDate("$dir/calendar_dates.txt", $date);
        $tripRoutes = $this->tripsForServices("$dir/trips.txt", $serviceIds);

        ['originDepartures' => $originDepartures, 'tripStops' => $tripStops, 'tripsByRoute' => $tripsByRoute]
            = $this->scanStopTimes("$dir/stop_times.txt", $tripRoutes, $stopNames);

        $lines = [];

        foreach ($tripsByRoute as $routeId => $tripIds) {
            $route = $routes[$routeId] ?? null;
            if (! $route) {
                continue; // route_id vu dans trips.txt mais absent de routes.txt — donnée incohérente, on ignore plutôt que de planter.
            }

            $type = self::ROUTE_TYPES[$route['route_type']] ?? 'bus';

            $representativeTrip = $this->longestTrip($tripIds, $tripStops);
            $stops = $representativeTrip ? array_values($tripStops[$representativeTrip]) : [];

            $departures = $originDepartures[$routeId] ?? [];
            sort($departures);

            $lines[] = [
                'gtfs_route_id' => $routeId,
                'name' => $this->displayName($type, $route['route_short_name']),
                'long_name' => $route['route_long_name'] ?: null,
                'slug' => $this->slugFor($type, $route['route_short_name']),
                'type' => $type,
                'color' => $route['route_color'] ?: null,
                'stops' => $stops,
                'first_departure' => $departures[0] ?? null,
                'last_departure' => $departures === [] ? null : end($departures),
                'frequency_minutes' => $this->averageFrequency($departures),
                'trip_count' => count($tripIds),
                'is_active' => true,
            ];
        }

        return $lines;
    }

    /** Premier mercredi à partir d'aujourd'hui inclus — voir docblock de la classe. */
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
        $tripRoutes = [];
        foreach ($this->readCsv($path) as $row) {
            if (isset($serviceIds[$row['service_id']])) {
                $tripRoutes[$row['trip_id']] = $row['route_id'];
            }
        }

        return $tripRoutes;
    }

    /**
     * Un seul passage sur stop_times.txt (le plus gros fichier, ~88 Mo sur
     * le flux complet — mais ici filtré aux ~12 700 trajets de la journée
     * représentative, voir docblock de la classe) : accumule en même temps
     * les horaires de départ au premier arrêt (fréquence/premier/dernier
     * départ) ET la liste ordonnée des arrêts de chaque trajet (pour
     * choisir ensuite le trajet le plus complet par ligne, voir
     * `longestTrip()`).
     *
     * @param  array<string, string>  $tripRoutes
     * @param  array<string, string>  $stopNames
     */
    private function scanStopTimes(string $path, array $tripRoutes, array $stopNames): array
    {
        $originDepartures = [];
        $tripStops = [];
        $tripsByRoute = [];

        foreach ($this->readCsv($path) as $row) {
            $tripId = $row['trip_id'];
            $routeId = $tripRoutes[$tripId] ?? null;
            if ($routeId === null) {
                continue; // trajet hors journée représentative — ignoré.
            }

            $tripsByRoute[$routeId][$tripId] = true;

            if ($row['stop_sequence'] === '1') {
                $originDepartures[$routeId][] = $row['departure_time'];
            }

            $tripStops[$tripId][(int) $row['stop_sequence']] = $stopNames[$row['stop_id']] ?? $row['stop_id'];
        }

        // `array_keys(..., true)` ci-dessus utilisé comme un set — on
        // reconvertit en simple liste de trip_id par route pour l'appelant.
        return [
            'originDepartures' => $originDepartures,
            'tripStops' => $tripStops,
            'tripsByRoute' => array_map('array_keys', $tripsByRoute),
        ];
    }

    /** @param list<string> $tripIds @param array<string, array<int, string>> $tripStops */
    private function longestTrip(array $tripIds, array $tripStops): ?string
    {
        $best = null;
        $bestCount = -1;
        foreach ($tripIds as $tripId) {
            $count = count($tripStops[$tripId] ?? []);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $tripId;
            }
        }

        if ($best !== null) {
            ksort($tripStops[$best]);
        }

        return $best;
    }

    /** @param list<string> $departures (format "HH:MM:SS", heure pouvant dépasser 24 pour un trajet après minuit — convention GTFS standard) */
    private function averageFrequency(array $departures): ?int
    {
        if (count($departures) < 2) {
            return null;
        }

        $minutes = array_map(fn ($t) => $this->timeToMinutes($t), $departures);
        sort($minutes);

        $gaps = [];
        for ($i = 1; $i < count($minutes); $i++) {
            $gaps[] = $minutes[$i] - $minutes[$i - 1];
        }

        return $gaps === [] ? null : (int) round(array_sum($gaps) / count($gaps));
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function displayName(string $type, string $shortName): string
    {
        return match ($type) {
            'metro' => "Métro {$shortName}",
            'tram' => "Tramway {$shortName}",
            'teleo' => 'Téléo',
            default => "Bus {$shortName}",
        };
    }

    private function slugFor(string $type, string $shortName): string
    {
        return $type === 'teleo' ? 'teleo' : Str::slug("{$type}-{$shortName}");
    }

    /** @return array<string, array<string, string>> keyed by $keyColumn */
    private function indexBy(string $path, string $keyColumn): array
    {
        $indexed = [];
        foreach ($this->readCsv($path) as $row) {
            $indexed[$row[$keyColumn]] = $row;
        }

        return $indexed;
    }

    /**
     * Lecture ligne à ligne (jamais `file()`/`file_get_contents()` — les
     * fichiers GTFS les plus gros font plusieurs dizaines de Mo) : mémoire
     * bornée quel que soit le nombre de lignes du fichier source.
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
