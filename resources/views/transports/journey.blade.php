<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Transports', 'href' => '/transports'], ['label' => 'Itinéraire']]" />

        <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Itinéraire entre 2 arrêts</h1>
        <p class="mt-2 text-ink-600">
            Trouvez les lignes de bus, métro, tramway ou téléphérique qui relient directement 2 arrêts à Toulouse.
        </p>

        {{-- Recherche d'itinéraire (demande client, 16/10/2026 — "un peu comme
        dans tisseo.fr") : limitée aux trajets DIRECTS, voir docblock de
        TransportController::journey(). GET + `from`/`to` : partageable,
        revenir en arrière fonctionne. --}}
        <form method="GET" class="mt-6 rounded-2xl border border-ink-100 bg-white p-4 shadow-sm sm:p-6">
            <div class="grid gap-3 sm:grid-cols-[1fr_auto_1fr]">
                <div>
                    <label for="journey-from" class="text-xs font-medium uppercase tracking-wide text-ink-400">Arrêt de départ</label>
                    <input id="journey-from" type="search" name="from" value="{{ $from }}" list="transport-stops" autocomplete="off"
                        placeholder="Ex. Capitole" required
                        class="mt-1 w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none">
                </div>
                <button type="button" title="Inverser le départ et l'arrivée"
                    onclick="const a=document.getElementById('journey-from'),b=document.getElementById('journey-to');[a.value,b.value]=[b.value,a.value];"
                    class="hidden h-9 w-9 shrink-0 self-end rounded-full border border-ink-200 bg-white text-ink-500 transition hover:bg-ink-50 sm:block">
                    ⇄
                </button>
                <div>
                    <label for="journey-to" class="text-xs font-medium uppercase tracking-wide text-ink-400">Arrêt d'arrivée</label>
                    <input id="journey-to" type="search" name="to" value="{{ $to }}" list="transport-stops" autocomplete="off"
                        placeholder="Ex. Jean Jaurès" required
                        class="mt-1 w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none">
                </div>
            </div>
            <datalist id="transport-stops">
                @foreach ($stopNames as $stop)
                    <option value="{{ $stop }}"></option>
                @endforeach
            </datalist>
            <div class="mt-4 flex items-center gap-3">
                <x-ui.button type="submit" size="sm">Rechercher</x-ui.button>
                @if ($from !== '' || $to !== '')
                    <a href="/transports/itineraire" class="text-sm text-brand-700 underline decoration-dotted underline-offset-2">Réinitialiser</a>
                @endif
            </div>
        </form>

        @if ($from !== '' && $to !== '')
            <div class="mt-8">
                @if ($results->isEmpty())
                    <div class="rounded-2xl border border-ink-100 bg-white p-6 text-center shadow-sm">
                        <p class="text-ink-700">Aucune ligne ne relie directement « {{ $from }} » à « {{ $to }} ».</p>
                        <p class="mt-2 text-sm text-ink-500">
                            Essayez un autre nom d'arrêt, ou cherchez un trajet avec correspondance sur
                            <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="text-brand-700 underline decoration-dotted underline-offset-2">tisseo.fr</a>.
                        </p>
                    </div>
                @else
                    <p class="text-sm text-ink-600">
                        {{ $results->count() }} ligne(s) relient directement ces 2 arrêts.
                    </p>
                    <ul class="mt-4 space-y-3">
                        @foreach ($results as $result)
                            @php $line = $result['line']; @endphp
                            <li>
                                <a
                                    href="/transports/{{ $line->slug }}"
                                    data-track="transport_line:{{ $line->id }}:transports_journey"
                                    class="flex items-center justify-between gap-4 rounded-2xl border border-ink-100 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <div class="flex items-center gap-3">
                                        <span class="h-6 w-6 shrink-0 rounded-full" style="background-color: #{{ $line->color ?? 'cc0000' }};" aria-hidden="true"></span>
                                        <div>
                                            <p class="font-heading font-semibold text-ink-900">{{ $line->name }}</p>
                                            <p class="text-xs text-ink-500">
                                                {{ $result['from_stop'] }} → {{ $result['to_stop'] }}
                                                @if ($result['reversed'])
                                                    <span class="text-ink-400">(sens retour)</span>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right text-xs text-ink-500">
                                        <p>{{ $result['stops_between'] }} arrêt(s)</p>
                                        @if ($line->frequency_minutes)
                                            <p>~{{ $line->frequency_minutes }} min de fréquence</p>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <p class="mt-8 text-xs text-ink-400">
            Trajets directs uniquement (une seule ligne, sans changement), calculés à partir de l'open data Tisséo
            (horaires théoriques d'un mercredi type, licence ODbL) — pas de temps réel. Pour les itinéraires avec
            correspondance et le temps réel, direction <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="underline decoration-dotted underline-offset-2 hover:text-brand-700">tisseo.fr</a>.
            Vous pouvez aussi <a href="/transports" class="underline decoration-dotted underline-offset-2 hover:text-brand-700">chercher les lignes desservant un seul arrêt</a>.
        </p>
    </div>
</x-layouts.app>
