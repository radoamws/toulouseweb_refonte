<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Transports']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Transports en commun à Toulouse</h1>
            {{-- Recherche par arrêt desservi (demande client, 14/10/2026) —
            GET + `?q=` : partageable/revenir en arrière fonctionne, même
            convention que la recherche agenda/annuaire. --}}
            <form method="GET" class="flex gap-2">
                <input type="search" name="q" value="{{ $query }}" placeholder="Chercher un arrêt (ex. Capitole, Jean Jaurès…)"
                    class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-72">
                <x-ui.button type="submit" variant="outline" size="sm">Chercher</x-ui.button>
            </form>
        </div>

        {{-- Itinéraire entre 2 arrêts (demande client, 16/10/2026 — "un peu
        comme dans tisseo.fr"), mis en avant comme sur la home de tisseo.fr. --}}
        <a href="/transports/itineraire" class="mt-6 flex items-center justify-between gap-4 rounded-2xl border border-brand-200 bg-brand-50 p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-5">
            <div>
                <p class="font-heading font-semibold text-brand-900">Chercher un itinéraire entre 2 arrêts</p>
                <p class="text-sm text-brand-700">Quelles lignes relient directement un arrêt à un autre ?</p>
            </div>
            <span class="shrink-0 text-xl text-brand-700" aria-hidden="true">→</span>
        </a>
        <p class="mt-2 text-ink-600">
            Métro, tramway, téléphérique Téléo et bus Tisséo — premier/dernier départ, fréquence indicative et arrêts desservis.
            Horaires théoriques, issus de l'open data Tisséo ; pour le temps réel, direction
            <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="text-brand-700 underline decoration-dotted underline-offset-2">tisseo.fr</a>.
        </p>

        @if ($query !== '')
            <div class="mt-4 flex items-center gap-3 text-sm">
                <p class="text-ink-600">
                    {{ $lines->flatten(1)->count() }} ligne(s) desservant un arrêt correspondant à « {{ $query }} ».
                </p>
                <a href="/transports" class="text-brand-700 underline decoration-dotted underline-offset-2">Réinitialiser</a>
            </div>
        @endif

        @if ($lines->isEmpty())
            <p class="mt-10 text-ink-500">
                @if ($query !== '')
                    Aucune ligne ne dessert un arrêt correspondant à « {{ $query }} ».
                @else
                    Aucune ligne disponible pour le moment.
                @endif
            </p>
        @else
            <div class="mt-8 space-y-10">
                @foreach ($lines as $type => $typeLines)
                    <section aria-label="{{ \App\Models\TransportLine::TYPE_LABELS[$type] ?? $type }}">
                        <h2 class="font-heading text-lg font-semibold text-ink-900">{{ \App\Models\TransportLine::TYPE_LABELS[$type] ?? ucfirst($type) }}</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($typeLines as $line)
                                @php
                                    // Arrêt réellement trouvé pour CETTE ligne (demande
                                    // client) — affiché sous le nom pour que le résultat
                                    // reste compréhensible même si "Jean Jaurès" matche
                                    // sur plusieurs lignes différentes pour des raisons
                                    // différentes (gares/correspondances homonymes).
                                    $matchedStop = $query !== ''
                                        ? collect($line->stops)->first(fn ($stop) => str_contains(\Illuminate\Support\Str::lower($stop), \Illuminate\Support\Str::lower($query)))
                                        : null;
                                @endphp
                                <a
                                    href="/transports/{{ $line->slug }}"
                                    data-track="transport_line:{{ $line->id }}:transports_listing"
                                    class="inline-flex items-center gap-2 rounded-full border border-ink-100 bg-white px-3 py-1.5 text-sm font-medium text-ink-800 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: #{{ $line->color ?? 'cc0000' }};" aria-hidden="true"></span>
                                    <span>
                                        {{ $line->name }}
                                        @if ($matchedStop)
                                            <span class="block text-xs font-normal text-ink-500">{{ $matchedStop }}</span>
                                        @endif
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
