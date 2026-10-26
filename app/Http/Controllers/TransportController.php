<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\TransportLine;
use App\Models\TransportStop;
use App\Services\Geocoding\AddressGeocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Lignes de transport Tisséo (brief roadmap "contenu", 14/10/2026, demande
 * client) — résumé par ligne, voir App\Console\Commands\ImportTisseoGtfs.
 */
class TransportController extends Controller
{
    public function index(Request $request): View
    {
        // Tri par type en PHP plutôt qu'un ORDER BY FIELD() (MySQL
        // uniquement, pas portable sur SQLite utilisé en test) — ~123
        // lignes au total, un tri en mémoire est largement suffisant.
        $typeOrder = ['metro' => 0, 'tram' => 1, 'teleo' => 2, 'bus' => 3];

        // Recherche par arrêt desservi (brief roadmap "contenu", demande
        // client 14/10/2026) — `stops` est un simple JSON par ligne (pas de
        // table `transport_stops` séparée, voir docblock de la migration),
        // donc filtré ici en PHP plutôt qu'en SQL (portable SQLite/MySQL,
        // et ~123 lignes au total : un filtrage en mémoire est largement
        // suffisant, pas besoin d'index dédié).
        $query = trim((string) $request->string('q'));

        $lines = TransportLine::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->when($query !== '', fn ($collection) => $collection->filter(
                fn (TransportLine $line) => collect($line->stops)->contains(
                    fn ($stop) => str_contains(Str::lower($stop), Str::lower($query))
                )
            ))
            ->sortBy(fn ($line) => $typeOrder[$line->type] ?? 99)
            ->groupBy('type');

        $this->recordPageView($request);

        return view('transports.index', [
            'lines' => $lines,
            'query' => $query,
            'seo' => Page::where('key', 'seo-menu-transports')->first()?->resolveSeo() ?? [],
        ]);
    }

    /**
     * Recherche d'itinéraire entre 2 arrêts OU adresses/rues/quartiers
     * (brief roadmap "contenu", demande client 16/10/2026 puis 26/10/2026 —
     * "le client veut que le choix entre 2 rues se fasse, pas juste entre 2
     * arrêts de bus [...] des fois les gens veulent juste aller entre 2
     * quartiers sans savoir l'arrêt"), sur les VRAIS horaires par arrêt
     * (voir App\Console\Commands\ImportTisseoSchedule) : trajets directs ET
     * avec 1 correspondance. Benchmarké en réel le 16/10/2026 (~700-800ms
     * par recherche avec les index en place).
     *
     * Résolution en cascade (voir resolveStopIds()) : d'abord un nom
     * d'arrêt exact, puis un substring, puis — seulement si AUCUN arrêt ne
     * correspond — un géocodage d'adresse (App\Services\Geocoding\AddressGeocoder)
     * suivi d'une recherche des arrêts les plus proches du point obtenu.
     * Le géocodage n'est donc jamais appelé pour le cas le plus courant
     * (nom d'arrêt tapé/choisi dans l'autocomplete), seulement en repli.
     */
    public function journey(Request $request): View
    {
        $from = trim((string) $request->string('from'));
        $to = trim((string) $request->string('to'));

        $direct = collect();
        $transfers = collect();
        $fromResolution = null;
        $toResolution = null;

        if ($from !== '' && $to !== '') {
            $allStops = TransportStop::query()->get(['id', 'name']);
            [$fromIds, $fromResolution] = $this->resolveStopIds($allStops, $from);
            [$toIds, $toResolution] = $this->resolveStopIds($allStops, $to);

            if ($fromIds !== [] && $toIds !== []) {
                // Chargées une seule fois, réutilisées pour les 2 recherches
                // (direct + correspondance) — ~123 lignes, voir docblock de index().
                $linesByRoute = TransportLine::where('is_active', true)->get()->keyBy('gtfs_route_id');

                $direct = $this->directConnections($fromIds, $toIds, $linesByRoute);
                $transfers = $this->oneTransferConnections($fromIds, $toIds, $linesByRoute);
            }
        }

        // Autocomplete : tous les arrêts RÉELS du réseau (stops.txt complet),
        // pas seulement ceux d'un trajet représentatif par ligne comme
        // l'ancienne version — dédupliqué par graphie exacte (une seule
        // source GTFS, pas de variantes orthographiques à normaliser ici).
        $stopNames = TransportStop::orderBy('name')->pluck('name')->unique()->values();

        $this->recordPageView($request);

        return view('transports.journey', [
            'from' => $from,
            'to' => $to,
            'direct' => $direct,
            'transfers' => $transfers,
            'fromResolution' => $fromResolution,
            'toResolution' => $toResolution,
            'stopNames' => $stopNames,
            'seo' => [],
        ]);
    }

    /**
     * @return array{0: list<int>, 1: array{label: string, stop_names: list<string>}|null}
     *         le 2e élément n'est non-null QUE si la résolution est passée
     *         par le géocodage (jamais pour un nom d'arrêt direct) — sert à
     *         afficher "« Rue Alsace Lorraine » → arrêt le plus proche :
     *         Capitole" dans la vue, pour que l'utilisateur comprenne quel
     *         arrêt réel a été utilisé.
     */
    private function resolveStopIds(Collection $allStops, string $query): array
    {
        $stopIds = $this->stopIdsMatching($allStops, $query);
        if ($stopIds !== []) {
            return [$stopIds, null];
        }

        $geocoded = app(AddressGeocoder::class)->geocode($query);
        if (! $geocoded) {
            return [[], null];
        }

        $nearestIds = $this->nearestStopIds($geocoded['lat'], $geocoded['lon']);
        $stopNames = $nearestIds === [] ? [] : TransportStop::whereIn('id', $nearestIds)->pluck('name')->unique()->values()->all();

        return [$nearestIds, ['label' => $geocoded['label'], 'stop_names' => $stopNames]];
    }

    /**
     * Arrêts les plus proches d'un point (formule de Haversine, calculée en
     * PHP — portable SQLite/MySQL, pas de fonctions trigonométriques SQL
     * communes aux deux moteurs, même raisonnement que le tri par type de
     * index()). ~5731 arrêts au total : un passage en mémoire reste
     * largement suffisant (quelques dizaines de ms).
     *
     * Rayon de 1,5 km par défaut : distance raisonnable à pied jusqu'à un
     * arrêt en zone urbaine — au-delà, mieux vaut ne proposer aucun arrêt
     * que d'en suggérer un trop éloigné pour être pertinent.
     *
     * @return list<int>
     */
    private function nearestStopIds(float $lat, float $lon, int $limit = 3, float $maxKm = 1.5): array
    {
        return TransportStop::query()
            ->whereNotNull('lat')->whereNotNull('lon')
            ->get(['id', 'lat', 'lon'])
            ->map(fn ($stop) => [
                'id' => $stop->id,
                'distance' => $this->haversineKm($lat, $lon, (float) $stop->lat, (float) $stop->lon),
            ])
            ->filter(fn ($s) => $s['distance'] <= $maxKm)
            ->sortBy('distance')
            ->take($limit)
            ->pluck('id')
            ->all();
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Correspondance exacte (nom normalisé) privilégiée pour éviter le bruit
     * d'un substring trop large quand l'utilisateur choisit un nom complet
     * dans l'autocomplete ; repli sur un substring borné (30 arrêts max,
     * évite un `WHERE IN` démesuré sur une recherche trop générique type
     * une seule lettre).
     *
     * @return list<int>
     */
    private function stopIdsMatching(Collection $allStops, string $query): array
    {
        $normalizedQuery = self::normalize($query);

        $exact = $allStops->filter(fn ($stop) => self::normalize($stop->name) === $normalizedQuery);
        if ($exact->isNotEmpty()) {
            return $exact->pluck('id')->all();
        }

        return $allStops
            ->filter(fn ($stop) => str_contains(self::normalize($stop->name), $normalizedQuery))
            ->take(30)
            ->pluck('id')
            ->all();
    }

    /**
     * Self-jointure sur `transport_stop_times` (même trip_id, séquence
     * croissante) — benchmarké en réel le 16/10/2026 (~130ms). Regroupé par
     * ligne (`gtfs_route_id`), pas par trajet individuel : plusieurs
     * trajets d'une même ligne desservant les 2 arrêts ne doivent produire
     * qu'un seul résultat.
     *
     * @param  list<int>  $fromIds
     * @param  list<int>  $toIds
     */
    private function directConnections(array $fromIds, array $toIds, Collection $linesByRoute): Collection
    {
        return DB::table('transport_stop_times as a')
            ->join('transport_stop_times as b', 'a.transport_trip_id', '=', 'b.transport_trip_id')
            ->join('transport_trips as t', 't.id', '=', 'a.transport_trip_id')
            ->whereIn('a.transport_stop_id', $fromIds)
            ->whereIn('b.transport_stop_id', $toIds)
            ->whereColumn('b.stop_sequence', '>', 'a.stop_sequence')
            ->selectRaw('t.gtfs_route_id as route_id, MIN(b.stop_sequence - a.stop_sequence) as stops_between')
            ->groupBy('t.gtfs_route_id')
            ->get()
            ->map(fn ($row) => isset($linesByRoute[$row->route_id]) ? [
                'line' => $linesByRoute[$row->route_id],
                'stops_between' => (int) $row->stops_between,
            ] : null)
            ->filter()
            ->sortBy('stops_between')
            ->values();
    }

    /**
     * Trajets avec 1 correspondance : arrêts atteignables en 1 saut depuis
     * le départ (`leg1`) intersectés avec les arrêts pouvant atteindre
     * l'arrivée en 1 saut (`leg2`) — SEULEMENT 2 requêtes au total (pas une
     * par arrêt candidat), benchmarké en réel le 16/10/2026.
     *
     * ⚠️ Regroupé par NOM d'arrêt, PAS par `transport_stop_id` : vérifié en
     * réel le 16/10/2026 sur "Université Paul Sabatier" (pôle d'échange
     * Téléo/Métro B), 16 `stop_id` GTFS distincts y partagent ce même nom
     * (un par quai/ligne — convention GTFS standard, aucun `parent_station`
     * importé ici). Un groupement par `stop_id` exact ne trouve donc
     * QUASIMENT JAMAIS de correspondance entre 2 MODES différents (bus/
     * métro/tram/téléo), puisque chaque mode arrive sur un quai différent —
     * exactement le cas d'usage principal de cette fonctionnalité. Risque
     * assumé en échange : un nom générique répété dans plusieurs quartiers
     * (ex. "Mairie", "Église") pourrait suggérer une correspondance à un nom
     * homonyme mais physiquement différent — préférable à l'inverse (ne
     * jamais proposer les vraies correspondances multimodales).
     *
     * Exclut les correspondances où les 2 jambes utilisent la MÊME ligne
     * (pas une vraie correspondance). Limité aux 10 meilleures options
     * (total d'arrêts croissant).
     *
     * @param  list<int>  $fromIds
     * @param  list<int>  $toIds
     */
    private function oneTransferConnections(array $fromIds, array $toIds, Collection $linesByRoute): Collection
    {
        $leg1 = DB::table('transport_stop_times as a')
            ->join('transport_stop_times as b', 'a.transport_trip_id', '=', 'b.transport_trip_id')
            ->join('transport_trips as t', 't.id', '=', 'a.transport_trip_id')
            ->join('transport_stops as s', 's.id', '=', 'b.transport_stop_id')
            ->whereIn('a.transport_stop_id', $fromIds)
            ->whereColumn('b.stop_sequence', '>', 'a.stop_sequence')
            ->selectRaw('s.name as transfer_stop, t.gtfs_route_id as route_id, MIN(b.stop_sequence - a.stop_sequence) as stops_between')
            ->groupBy('s.name', 't.gtfs_route_id')
            ->get()
            ->groupBy('transfer_stop');

        $leg2 = DB::table('transport_stop_times as a')
            ->join('transport_stop_times as b', 'a.transport_trip_id', '=', 'b.transport_trip_id')
            ->join('transport_trips as t', 't.id', '=', 'a.transport_trip_id')
            ->join('transport_stops as s', 's.id', '=', 'a.transport_stop_id')
            ->whereIn('b.transport_stop_id', $toIds)
            ->whereColumn('b.stop_sequence', '>', 'a.stop_sequence')
            ->selectRaw('s.name as transfer_stop, t.gtfs_route_id as route_id, MIN(b.stop_sequence - a.stop_sequence) as stops_between')
            ->groupBy('s.name', 't.gtfs_route_id')
            ->get()
            ->groupBy('transfer_stop');

        $candidateStopNames = $leg1->keys()->intersect($leg2->keys());
        if ($candidateStopNames->isEmpty()) {
            return collect();
        }

        $results = collect();
        foreach ($candidateStopNames as $stopName) {
            foreach ($leg1[$stopName] as $first) {
                foreach ($leg2[$stopName] as $second) {
                    if ($first->route_id === $second->route_id) {
                        continue; // même ligne des 2 côtés : pas une vraie correspondance.
                    }

                    $firstLine = $linesByRoute[$first->route_id] ?? null;
                    $secondLine = $linesByRoute[$second->route_id] ?? null;
                    if (! $firstLine || ! $secondLine) {
                        continue; // route_id absent de transport_lines (import décalé dans le temps) — ignoré plutôt qu'un résultat incomplet.
                    }

                    $results->push([
                        'transfer_stop' => $stopName,
                        'first_line' => $firstLine,
                        'first_stops' => (int) $first->stops_between,
                        'second_line' => $secondLine,
                        'second_stops' => (int) $second->stops_between,
                        'total_stops' => (int) $first->stops_between + (int) $second->stops_between,
                    ]);
                }
            }
        }

        return $results->sortBy('total_stops')->take(10)->values();
    }

    /** Insensible à la casse ET aux accents (ex. "jean jaures" trouve "Jean Jaurès") — améliore sur le simple Str::lower() du filtre par arrêt de index(). */
    private static function normalize(string $value): string
    {
        return Str::of($value)->lower()->ascii()->toString();
    }

    public function show(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $line = TransportLine::where('slug', $slug)->where('is_active', true)->first();
        if (! $line) {
            return $this->redirectOrAbort($request->path());
        }

        $related = TransportLine::where('is_active', true)
            ->where('id', '!=', $line->id)
            ->where('type', $line->type)
            ->orderBy('name')
            ->limit(6)
            ->get();

        $this->recordPageView($request, 'transport_line', $line->id);

        return view('transports.show', [
            'line' => $line,
            'related' => $related,
            'seo' => $line->resolveSeo(),
        ]);
    }
}
