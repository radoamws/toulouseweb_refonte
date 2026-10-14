{{-- Pas de JSON-LD ici : schema.org n'a pas de type adapté à "une ligne de
transport en commun" (BusTrip/Trip désignent un trajet précis, pas une
ligne) — mieux vaut aucune donnée structurée qu'un type inapproprié (voir
l'audit Search Console du 02/10/2026 sur les Event, TECHNICAL_DOCUMENTATION.md
§67 : un mauvais typage structuré est lui-même une source d'erreurs GSC). --}}
<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Transports', 'href' => '/transports'], ['label' => $line->name]]" />

        <div class="flex items-center gap-3">
            <span class="h-6 w-6 shrink-0 rounded-full" style="background-color: #{{ $line->color ?? 'cc0000' }};" aria-hidden="true"></span>
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">{{ $line->name }}</h1>
        </div>
        @if ($line->long_name)
            <p class="mt-2 text-ink-600">{{ $line->long_name }}</p>
        @endif

        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
            @if ($line->first_departure)
                <div class="rounded-2xl border border-ink-100 bg-white p-4 text-center shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Premier départ</p>
                    <p class="mt-1 font-heading text-xl font-bold text-ink-900">{{ \App\Models\TransportLine::formatTime($line->first_departure) }}</p>
                </div>
            @endif
            @if ($line->last_departure)
                <div class="rounded-2xl border border-ink-100 bg-white p-4 text-center shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Dernier départ</p>
                    <p class="mt-1 font-heading text-xl font-bold text-ink-900">{{ \App\Models\TransportLine::formatTime($line->last_departure) }}</p>
                </div>
            @endif
            @if ($line->frequency_minutes)
                <div class="rounded-2xl border border-ink-100 bg-white p-4 text-center shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Fréquence indicative</p>
                    <p class="mt-1 font-heading text-xl font-bold text-ink-900">~{{ $line->frequency_minutes }} min</p>
                </div>
            @endif
        </div>

        <p class="mt-4 text-xs text-ink-400">
            Horaires théoriques un mercredi type, calculés à partir de l'open data Tisséo (licence ODbL) — pas le temps réel.
            Pour les horaires en direct et acheter un titre de transport : <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="underline decoration-dotted underline-offset-2 hover:text-brand-700">tisseo.fr</a>.
        </p>

        @if (! empty($line->stops))
            <h2 class="mt-10 font-heading text-lg font-semibold text-ink-900">Arrêts desservis</h2>
            <ol class="mt-3 space-y-1 border-l-2 pl-4" style="border-color: #{{ $line->color ?? 'cc0000' }};">
                @foreach ($line->stops as $stop)
                    <li class="text-sm text-ink-700">{{ $stop }}</li>
                @endforeach
            </ol>
        @endif

        @if ($related->isNotEmpty())
            <div class="mt-12">
                <h2 class="font-heading text-lg font-semibold text-ink-900">Autres lignes</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($related as $item)
                        <a
                            href="/transports/{{ $item->slug }}"
                            data-track="transport_line:{{ $item->id }}:transports_related"
                            class="inline-flex items-center gap-2 rounded-full border border-ink-100 bg-white px-3 py-1.5 text-sm font-medium text-ink-800 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                        >
                            <span class="h-3 w-3 rounded-full" style="background-color: #{{ $item->color ?? 'cc0000' }};" aria-hidden="true"></span>
                            {{ $item->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
