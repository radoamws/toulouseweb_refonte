{{--
    Carrousel transports de la home (brief roadmap "contenu", 14/10/2026,
    demande client : "crée un carousel selon ton inspiration pour
    représenter le transport, et le clic sur ce carousel renvoi vers
    /transports"). Un slide par mode réellement importé (voir
    HomeController::index(), `TransportLine::where('is_active', true)
    ->groupBy('type')`) — jamais affiché si aucune ligne n'a encore été
    importée (pas de bloc vide/trompeur avant le premier
    `transport:import-tisseo-gtfs`).

    Mêmes mécanique Alpine (autoplay + flèches + pastilles) que
    x-site.hero-slider ci-dessus sur la home, pour une interaction cohérente
    sur toute la page — mais CHAQUE slide est son propre lien vers
    /transports (une seule destination, demande client), pas de lien par
    diapositive comme le hero (administrable, lui).
--}}
@props(['counts'])

@php
    $modes = [
        'metro' => ['label' => 'Métro', 'tagline' => 'Un métro toutes les 1 à 3 minutes', 'gradient' => 'from-brand-600 to-brand-800'],
        'tram' => ['label' => 'Tramway', 'tagline' => 'La ligne T1 traverse la ville de bout en bout', 'gradient' => 'from-ink-700 to-ink-900'],
        'teleo' => ['label' => 'Téléo', 'tagline' => 'Le plus long téléphérique urbain de France', 'gradient' => 'from-accent-500 to-brand-700'],
        'bus' => ['label' => 'Bus Tisséo', 'tagline' => 'Tout le réseau, ligne par ligne', 'gradient' => 'from-ink-600 to-brand-700'],
    ];

    $slides = collect($modes)
        ->filter(fn ($mode, $type) => ($counts[$type] ?? 0) > 0)
        ->map(fn ($mode, $type) => [...$mode, 'type' => $type, 'count' => $counts[$type]])
        ->values();
@endphp

@if ($slides->isNotEmpty())
    <section
        x-data="{
            active: 0,
            count: {{ $slides->count() }},
            timer: null,
            start() {
                this.stop();
                this.timer = setInterval(() => { this.active = (this.active + 1) % this.count }, 4500);
            },
            stop() { if (this.timer) clearInterval(this.timer) },
        }"
        x-init="start()"
        @mouseenter="stop()"
        @mouseleave="start()"
        class="relative overflow-hidden rounded-3xl shadow-lg"
        aria-roledescription="carrousel"
        aria-label="Transports en commun à Toulouse"
    >
        <div class="relative h-44 sm:h-40">
            @foreach ($slides as $i => $slide)
                <a
                    href="/transports"
                    data-track="transport_carousel:{{ $i }}:homepage_transports"
                    x-show="active === {{ $i }}"
                    x-transition:enter="transition ease-out duration-500"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    @if ($i !== 0) x-cloak @endif
                    class="absolute inset-0 flex items-center gap-4 bg-gradient-to-br {{ $slide['gradient'] }} px-6 sm:gap-5 sm:px-10"
                >
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-white sm:h-20 sm:w-20">
                        <x-site.transport-icon :type="$slide['type']" class="h-8 w-8 sm:h-11 sm:w-11" />
                    </div>
                    <div class="min-w-0">
                        <p class="font-heading text-lg font-bold text-white sm:text-2xl">{{ $slide['label'] }}</p>
                        <p class="mt-0.5 hidden text-sm text-white/85 sm:block">{{ $slide['tagline'] }}</p>
                        <p class="mt-1 text-sm font-semibold text-white sm:mt-2">
                            {{ $slide['count'] }} {{ \Illuminate\Support\Str::plural('ligne', $slide['count']) }} · Voir les horaires →
                        </p>
                    </div>
                </a>
            @endforeach
        </div>

        @if ($slides->count() > 1)
            <button
                type="button"
                @click.prevent.stop="active = (active - 1 + count) % count"
                class="absolute left-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-sm transition hover:bg-white"
                aria-label="Mode de transport précédent"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#CC0000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4" aria-hidden="true">
                    <path d="M15.75 19.5L8.25 12l7.5-7.5" />
                </svg>
            </button>
            <button
                type="button"
                @click.prevent.stop="active = (active + 1) % count"
                class="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-sm transition hover:bg-white"
                aria-label="Mode de transport suivant"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#CC0000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4" aria-hidden="true">
                    <path d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            </button>

            <div class="absolute inset-x-0 bottom-3 flex justify-center gap-2">
                @foreach ($slides as $i => $slide)
                    <button
                        type="button"
                        @click.prevent.stop="active = {{ $i }}"
                        class="h-2 w-2 rounded-full transition"
                        :class="active === {{ $i }} ? 'bg-white w-6' : 'bg-white/50'"
                        aria-label="Aller au mode {{ $slide['label'] }}"
                    ></button>
                @endforeach
            </div>
        @endif
    </section>
@endif
