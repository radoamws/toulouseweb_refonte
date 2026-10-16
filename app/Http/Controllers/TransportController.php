<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\TransportLine;
use Illuminate\Http\Request;
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
     * Recherche d'itinéraire entre 2 arrêts (brief roadmap "contenu",
     * demande client 16/10/2026 — "un peu comme dans tisseo.fr"), limitée
     * aux trajets DIRECTS (une seule ligne, pas de correspondance) : les
     * données importées ne contiennent qu'un trajet représentatif par ligne
     * (voir ImportTisseoGtfs), pas d'horaires par arrêt ni de table
     * `transfers.txt` — un planificateur multi-lignes nécessiterait de
     * réimporter stop_times/trips/calendar en entier (hors scope, voir
     * TECHNICAL_DOCUMENTATION.md).
     */
    public function journey(Request $request): View
    {
        $from = trim((string) $request->string('from'));
        $to = trim((string) $request->string('to'));

        $typeOrder = ['metro' => 0, 'tram' => 1, 'teleo' => 2, 'bus' => 3];

        $results = collect();
        if ($from !== '' && $to !== '') {
            $results = TransportLine::where('is_active', true)->get()
                ->map(fn (TransportLine $line) => $this->matchJourney($line, $from, $to))
                ->filter()
                ->sort(fn ($a, $b) => $a['stops_between'] <=> $b['stops_between']
                    ?: ($typeOrder[$a['line']->type] ?? 99) <=> ($typeOrder[$b['line']->type] ?? 99)
                    ?: $a['line']->name <=> $b['line']->name)
                ->values();
        }

        // Autocomplete : noms d'arrêts distincts toutes lignes confondues,
        // dédupliqués par forme normalisée (accents/casse) en gardant la
        // première graphie rencontrée — ~123 lignes au total, un passage en
        // mémoire est largement suffisant (même raisonnement que le filtre
        // par arrêt de index(), voir son docblock).
        $stopNames = TransportLine::where('is_active', true)->get()
            ->flatMap(fn (TransportLine $line) => $line->stops)
            ->unique(fn (string $stop) => self::normalize($stop))
            ->sort()
            ->values();

        $this->recordPageView($request);

        return view('transports.journey', [
            'from' => $from,
            'to' => $to,
            'results' => $results,
            'stopNames' => $stopNames,
            'seo' => [],
        ]);
    }

    /**
     * @return array{line: TransportLine, from_stop: string, to_stop: string, stops_between: int, reversed: bool}|null
     */
    private function matchJourney(TransportLine $line, string $from, string $to): ?array
    {
        $normalizedFrom = self::normalize($from);
        $normalizedTo = self::normalize($to);

        $fromIndex = $toIndex = null;
        $fromStop = $toStop = null;

        foreach ($line->stops as $index => $stop) {
            $normalizedStop = self::normalize($stop);

            if ($fromIndex === null && str_contains($normalizedStop, $normalizedFrom)) {
                $fromIndex = $index;
                $fromStop = $stop;
            }

            if ($toIndex === null && str_contains($normalizedStop, $normalizedTo)) {
                $toIndex = $index;
                $toStop = $stop;
            }
        }

        if ($fromIndex === null || $toIndex === null || $fromIndex === $toIndex) {
            return null;
        }

        return [
            'line' => $line,
            'from_stop' => $fromStop,
            'to_stop' => $toStop,
            'stops_between' => abs($toIndex - $fromIndex),
            'reversed' => $fromIndex > $toIndex,
        ];
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
