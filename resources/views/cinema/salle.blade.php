@php
    // Convention confirmée par le legacy (`jour = $date->format('w')`, PHP
    // `date('w')` = 0 Dimanche…6 Samedi), reprise par `AllocineDriver`
    // (`$startsAt->dayOfWeek`) et par `ScreeningsRelationManager::WEEKDAYS`
    // dans l'admin — voir le correctif du 01/09/2026 documenté dans
    // resources/views/cinema/movie.blade.php et TECHNICAL_DOCUMENTATION.md.
    $weekdays = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    // ⚠️ Bug réel trouvé et corrigé (08/09/2026, audit SEO final,
    // TECHNICAL_DOCUMENTATION.md §24) : `external_url` n'est pas une URL
    // exploitable pour la quasi-totalité des salles (26/28 en base réelle) —
    // un fragment de requête AlloCiné legacy brut du type
    // "salle_gen_csalle=P0071.html", pas une URI absolue. Fait échouer la
    // validation Rich Results de Google (`url` doit être une URI absolue).
    // Utilise la page canonique de la salle elle-même, toujours valide.
    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'MovieTheater',
        'name' => $cinema->name,
        'address' => $cinema->address,
        'url' => route('cinema.salle', $cinema->slug),
        'geo' => $cinema->lat && $cinema->lng ? [
            '@type' => 'GeoCoordinates',
            'latitude' => $cinema->lat,
            'longitude' => $cinema->lng,
        ] : null,
    ]);

    $screeningsByMovie = $cinema->screenings->groupBy('movie.title');
@endphp

<x-layouts.app :seo="$seo">
    @push('head')
        <script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>
    @endpush

    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Cinéma', 'href' => '/cinema'], ['label' => $cinema->name]]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">{{ $cinema->name }}</h1>
        @if ($cinema->address)
            <p class="mt-2 text-ink-600">{{ $cinema->address }}</p>
        @endif

        <h2 class="mt-8 font-heading text-xl font-bold text-ink-900">Films à l'affiche</h2>
        @if ($screeningsByMovie->isEmpty())
            <p class="mt-3 text-ink-500">Aucune séance programmée actuellement.</p>
        @else
            <div class="mt-4 space-y-6">
                @foreach ($screeningsByMovie as $movieTitle => $screenings)
                    <div class="rounded-2xl border border-ink-100 bg-white p-5 shadow-sm">
                        <a href="/cinema/films/{{ $screenings->first()->movie?->slug }}" class="font-heading font-semibold text-ink-900 hover:text-brand-700">
                            {{ $movieTitle }}
                        </a>
                        @foreach ($screenings as $screening)
                            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                                @foreach ($screening->times as $time)
                                    {{-- Horaire cliquable vers la réservation sur le vrai site source
                                    (demande client — "2e scraping" du legacy, autoUpdateCinemaAllocineLiens/Liens2,
                                    voir docblock d'AllocineDriver::extractBookingUrl()) quand un lien a
                                    été capturé ; simple badge non cliquable sinon. --}}
                                    @if ($time->booking_url)
                                        <a
                                            href="{{ $time->booking_url }}"
                                            target="_blank"
                                            rel="noopener"
                                            data-track="screening_time:{{ $time->id }}:cinema_booking_click"
                                            title="Réserver — {{ $weekdays[$time->weekday] ?? '' }}"
                                            class="rounded-lg bg-ink-50 px-2 py-1 text-ink-700 underline decoration-dotted underline-offset-2 transition hover:bg-brand-50 hover:text-brand-700"
                                        >{{ $weekdays[$time->weekday] ?? '' }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}</a>
                                    @else
                                        <span class="rounded-lg bg-ink-50 px-2 py-1 text-ink-700">
                                            {{ $weekdays[$time->weekday] ?? '' }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @if ($related->isNotEmpty())
            <div class="mt-12">
                <h2 class="font-heading text-lg font-semibold text-ink-900">Autres salles</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-ui.card
                            :href="'/cinema/salles/'.$item->slug"
                            :title="$item->name"
                            :meta="$item->address"
                            :track="'cinema:'.$item->id.':cinema_salle_related'"
                        />
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
