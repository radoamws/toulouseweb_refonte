<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Transports', 'href' => '/transports'], ['label' => 'Itinéraire']]" />

        <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Itinéraire entre 2 arrêts, rues ou quartiers</h1>
        <p class="mt-2 text-ink-600">
            Trouvez les lignes de bus, métro, tramway ou téléphérique qui relient 2 points à Toulouse, en direct ou avec 1 correspondance.
            Pas besoin de connaître l'arrêt : une adresse, une rue ou un quartier fonctionnent aussi.
        </p>

        {{-- Recherche d'itinéraire (demande client, 16/10/2026 — "un peu comme
        dans tisseo.fr" — puis 26/10/2026 — "le choix entre 2 rues, pas juste
        entre 2 arrêts de bus" — puis 03/11/2026 — "toutes les rues doivent
        être en base [...] reprendre un peu tout le fonctionnement de
        tisseo.fr") : autocomplétion en direct (arrêts Tisséo + rues via
        l'API BAN, voir TransportController::autocomplete()) pour que ce soit
        le VISITEUR qui choisisse la bonne commune/rue parmi les résultats —
        corrige un vrai bug constaté ce jour-là (un géocodage "à l'aveugle"
        sur un nom court comme "Saint-Sernin" pouvait résoudre vers une
        commune homonyme à 15 km, voir docblock de AddressGeocoder::search()).
        Les champs cachés `from_lat`/`from_lon`/`to_lat`/`to_lon` ne sont
        renseignés QUE si une suggestion "Rue" a été cliquée : ils court-
        circuitent alors tout nouveau géocodage côté serveur (voir docblock de
        TransportController::resolveStopIds()). Reste un simple GET
        `from`/`to` : partageable, revenir en arrière fonctionne, et
        fonctionne même sans JavaScript (repli sur la résolution serveur
        existante). --}}
        <form
            method="GET"
            class="mt-6 rounded-2xl border border-ink-100 bg-white p-4 shadow-sm sm:p-6"
            x-data="{
                from: { query: @js($from), lat: null, lon: null, open: false, stops: [], addresses: [], timer: null },
                to: { query: @js($to), lat: null, lon: null, open: false, stops: [], addresses: [], timer: null },
                search(side) {
                    const state = this[side];
                    state.lat = null;
                    state.lon = null;
                    clearTimeout(state.timer);
                    const q = state.query.trim();
                    if (q.length < 3) {
                        state.stops = [];
                        state.addresses = [];
                        state.open = false;
                        return;
                    }
                    state.timer = setTimeout(() => this.fetchSuggestions(side, q), 300);
                },
                async fetchSuggestions(side, q) {
                    const state = this[side];
                    try {
                        const response = await fetch('/transports/autocompletion?q=' + encodeURIComponent(q));
                        if (!response.ok) return;
                        const data = await response.json();
                        state.stops = data.stops ?? [];
                        state.addresses = data.addresses ?? [];
                        state.open = (state.stops.length + state.addresses.length) > 0;
                    } catch (e) {
                        // Silencieux : le visiteur peut toujours valider le texte tapé librement (repli résolution serveur).
                    }
                },
                chooseStop(side, name) {
                    const state = this[side];
                    state.query = name;
                    state.lat = null;
                    state.lon = null;
                    state.open = false;
                },
                chooseAddress(side, address) {
                    const state = this[side];
                    state.query = address.label;
                    state.lat = address.lat;
                    state.lon = address.lon;
                    state.open = false;
                },
                swap() {
                    [this.from, this.to] = [this.to, this.from];
                },
            }"
        >
            <div class="grid gap-3 sm:grid-cols-[1fr_auto_1fr]">
                <div class="relative" @click.outside="from.open = false">
                    <label for="journey-from" class="text-xs font-medium uppercase tracking-wide text-ink-400">Départ</label>
                    <input
                        id="journey-from" type="text" name="from" autocomplete="off" required
                        x-model="from.query" @input="search('from')" @focus="from.open = (from.stops.length + from.addresses.length) > 0"
                        placeholder="Arrêt, rue ou quartier (ex. Capitole, rue Alsace Lorraine…)"
                        class="mt-1 w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <input type="hidden" name="from_lat" :value="from.lat">
                    <input type="hidden" name="from_lon" :value="from.lon">

                    <div x-show="from.open" x-cloak class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-ink-100 bg-white py-1 text-sm shadow-lg">
                        <template x-if="from.stops.length">
                            <div>
                                <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase text-ink-400">Arrêts Tisséo</p>
                                <template x-for="name in from.stops" :key="name">
                                    <button type="button" @click="chooseStop('from', name)" class="block w-full px-3 py-1.5 text-left hover:bg-brand-50" x-text="name"></button>
                                </template>
                            </div>
                        </template>
                        <template x-if="from.addresses.length">
                            <div>
                                <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase text-ink-400">Rue</p>
                                <template x-for="address in from.addresses" :key="address.label">
                                    <button type="button" @click="chooseAddress('from', address)" class="block w-full px-3 py-1.5 text-left hover:bg-brand-50" x-text="address.label"></button>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <button type="button" title="Inverser le départ et l'arrivée" @click="swap()"
                    class="hidden h-9 w-9 shrink-0 self-end rounded-full border border-ink-200 bg-white text-ink-500 transition hover:bg-ink-50 sm:block">
                    ⇄
                </button>

                <div class="relative" @click.outside="to.open = false">
                    <label for="journey-to" class="text-xs font-medium uppercase tracking-wide text-ink-400">Arrivée</label>
                    <input
                        id="journey-to" type="text" name="to" autocomplete="off" required
                        x-model="to.query" @input="search('to')" @focus="to.open = (to.stops.length + to.addresses.length) > 0"
                        placeholder="Arrêt, rue ou quartier (ex. Jean Jaurès, Saint-Cyprien…)"
                        class="mt-1 w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <input type="hidden" name="to_lat" :value="to.lat">
                    <input type="hidden" name="to_lon" :value="to.lon">

                    <div x-show="to.open" x-cloak class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-ink-100 bg-white py-1 text-sm shadow-lg">
                        <template x-if="to.stops.length">
                            <div>
                                <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase text-ink-400">Arrêts Tisséo</p>
                                <template x-for="name in to.stops" :key="name">
                                    <button type="button" @click="chooseStop('to', name)" class="block w-full px-3 py-1.5 text-left hover:bg-brand-50" x-text="name"></button>
                                </template>
                            </div>
                        </template>
                        <template x-if="to.addresses.length">
                            <div>
                                <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase text-ink-400">Rue</p>
                                <template x-for="address in to.addresses" :key="address.label">
                                    <button type="button" @click="chooseAddress('to', address)" class="block w-full px-3 py-1.5 text-left hover:bg-brand-50" x-text="address.label"></button>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <x-ui.button type="submit" size="sm">Rechercher</x-ui.button>
                @if ($from !== '' || $to !== '')
                    <a href="/transports/itineraire" class="text-sm text-brand-700 underline decoration-dotted underline-offset-2">Réinitialiser</a>
                @endif
            </div>
        </form>

        @php
            // "Résolution" = la recherche est passée par le géocodage d'une
            // adresse/rue (pas un nom d'arrêt direct), voir docblock de
            // TransportController::resolveStopIds(). `stop_names` vide =
            // adresse trouvée mais aucun arrêt à moins de 1,5 km (cas
            // distinct d'un simple "aucun trajet", voir plus bas).
            $noNearbyStop = ($fromResolution && empty($fromResolution['stop_names']))
                || ($toResolution && empty($toResolution['stop_names']));
        @endphp

        @if ($from !== '' && $to !== '')
            <div class="mt-8 space-y-10">
                @if ($fromResolution || $toResolution)
                    <div class="space-y-1 text-sm text-ink-500">
                        @if ($fromResolution && $fromResolution['stop_names'] !== [])
                            <p>« {{ $fromResolution['label'] }} » → arrêt(s) le(s) plus proche(s) : {{ implode(', ', $fromResolution['stop_names']) }}</p>
                        @endif
                        @if ($toResolution && $toResolution['stop_names'] !== [])
                            <p>« {{ $toResolution['label'] }} » → arrêt(s) le(s) plus proche(s) : {{ implode(', ', $toResolution['stop_names']) }}</p>
                        @endif
                    </div>
                @endif

                @if ($noNearbyStop)
                    <div class="rounded-2xl border border-ink-100 bg-white p-6 text-center shadow-sm">
                        <p class="text-ink-700">Adresse trouvée, mais aucun arrêt à moins de 1,5 km.</p>
                        <p class="mt-2 text-sm text-ink-500">Essayez un arrêt ou une adresse plus proche du réseau Tisséo.</p>
                    </div>
                @elseif ($direct->isEmpty() && $transfers->isEmpty())
                    <div class="rounded-2xl border border-ink-100 bg-white p-6 text-center shadow-sm">
                        <p class="text-ink-700">Aucun trajet trouvé entre « {{ $from }} » et « {{ $to }} » (direct ou avec 1 correspondance).</p>
                        <p class="mt-2 text-sm text-ink-500">
                            Essayez un autre nom d'arrêt/adresse, ou cherchez un trajet avec plusieurs correspondances sur
                            <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="text-brand-700 underline decoration-dotted underline-offset-2">tisseo.fr</a>.
                        </p>
                    </div>
                @else
                    @if ($direct->isNotEmpty())
                        <section>
                            <h2 class="font-heading text-lg font-semibold text-ink-900">Trajets directs</h2>
                            <ul class="mt-4 space-y-3">
                                @foreach ($direct as $result)
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
                                                    {{-- Points d'arrêt réels de montée/descente (demande client, 03/11/2026 — "indique les différents points d'arrêt"). --}}
                                                    @if ($result['board_stop'] && $result['alight_stop'])
                                                        <p class="text-xs text-ink-500">{{ $result['board_stop'] }} → {{ $result['alight_stop'] }}</p>
                                                    @endif
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
                        </section>
                    @endif

                    @if ($transfers->isNotEmpty())
                        <section>
                            <h2 class="font-heading text-lg font-semibold text-ink-900">Avec 1 correspondance</h2>
                            <ul class="mt-4 space-y-3">
                                @foreach ($transfers as $result)
                                    <li class="rounded-2xl border border-ink-100 bg-white p-4 shadow-sm">
                                        {{-- Chaîne complète des points d'arrêt (demande client, 03/11/2026 —
                                        "indique les différents points d'arrêt", voir capture tisseo.fr jointe) :
                                        montée initiale → correspondance → descente finale. --}}
                                        <div class="flex flex-wrap items-center gap-2 text-sm">
                                            @if ($result['origin_stop'])
                                                <span class="font-medium text-ink-700">{{ $result['origin_stop'] }}</span>
                                                <span class="text-ink-400">→</span>
                                            @endif
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-ink-50 px-2.5 py-1 font-medium text-ink-800">
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: #{{ $result['first_line']->color ?? 'cc0000' }};" aria-hidden="true"></span>
                                                {{ $result['first_line']->name }}
                                            </span>
                                            <span class="text-ink-400">→</span>
                                            <span class="font-medium text-ink-700">{{ $result['transfer_stop'] }}</span>
                                            <span class="text-ink-400">→</span>
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-ink-50 px-2.5 py-1 font-medium text-ink-800">
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: #{{ $result['second_line']->color ?? 'cc0000' }};" aria-hidden="true"></span>
                                                {{ $result['second_line']->name }}
                                            </span>
                                            @if ($result['destination_stop'])
                                                <span class="text-ink-400">→</span>
                                                <span class="font-medium text-ink-700">{{ $result['destination_stop'] }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-2 text-xs text-ink-500">
                                            {{ $result['first_stops'] }} arrêt(s) jusqu'à la correspondance, puis {{ $result['second_stops'] }} arrêt(s)
                                            ({{ $result['total_stops'] }} au total).
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endif
            </div>
        @endif

        <p class="mt-8 text-xs text-ink-400">
            Trajets directs et avec 1 correspondance, calculés à partir de l'open data Tisséo (horaires théoriques
            d'un mercredi type, licence ODbL) — pas de temps réel. Les adresses/rues sont résolues via la Base
            Adresse Nationale (adresse.data.gouv.fr), puis ramenées à l'arrêt le plus proche. Pour les itinéraires
            avec plusieurs correspondances et le temps réel, direction <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="underline decoration-dotted underline-offset-2 hover:text-brand-700">tisseo.fr</a>.
            Vous pouvez aussi <a href="/transports" class="underline decoration-dotted underline-offset-2 hover:text-brand-700">chercher les lignes desservant un seul arrêt</a>.
        </p>
    </div>
</x-layouts.app>
