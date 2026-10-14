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
