@php
    // Convention confirmée par le legacy (`jour = $date->format('w')`, PHP
    // `date('w')` = 0 Dimanche…6 Samedi), reprise par `AllocineDriver`
    // (`$startsAt->dayOfWeek`) et par `ScreeningsRelationManager::WEEKDAYS`
    // dans l'admin — voir le correctif du 01/09/2026 documenté dans
    // resources/views/cinema/movie.blade.php et TECHNICAL_DOCUMENTATION.md.
    // Le tableau des jours vit désormais dans
    // resources/views/components/cinema/screening-time.blade.php (partagé
    // avec cinema/movie.blade.php).

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

@php
    // Associe chaque ScreeningTime à sa Screening parente SANS requête
    // supplémentaire (lazy-load évité : `times` est déjà eager-loadée mais
    // pas `times.screening` — on a déjà la Screening sous la main ici) pour
    // que <x-cinema.screening-time> (qui a besoin de `screening->end_date`
    // pour désactiver un lien expiré) reste réutilisable telle quelle dans
    // la grille jour par jour ci-dessous.
    $weekdayEntries = $screeningsByMovie->map(
        fn ($screenings) => $screenings
            ->flatMap(fn ($screening) => $screening->times->map(fn ($time) => ['screening' => $screening, 'time' => $time]))
            ->groupBy(fn ($entry) => (int) $entry['time']->weekday)
    );
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
            {{-- Demande client (05/10/2026) : "j'avais accès à une grille jour
            par jour et non une liste de film qui ne sont pas placés en ordre
            chronologique [...] l'entrée par salle est plus pertinente" —
            restaure le tableau "1 film par ligne x 7 jours" de l'ancienne
            version du site (mercredi à mardi, ordre chronologique réel),
            avec une miniature compacte pour garder une hauteur de ligne
            raisonnable (demande explicite : pas de défilement excessif). --}}
            <p class="mt-1 text-sm text-ink-500">
                Semaine du {{ $weekDays->first()->translatedFormat('d F') }} au {{ $weekDays->last()->translatedFormat('d F Y') }}
            </p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[800px] border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 bg-white px-3 py-2 text-left font-heading text-ink-900">Films</th>
                            @foreach ($weekDays as $day)
                                <th class="px-2 py-2 text-center font-heading text-xs font-semibold uppercase text-ink-700">
                                    {{ $day->translatedFormat('D') }}<br>{{ $day->format('d/m') }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($screeningsByMovie as $movieTitle => $screenings)
                            @php $movie = $screenings->first()->movie; @endphp
                            <tr class="border-t border-ink-100 align-top">
                                <td class="sticky left-0 z-10 bg-white py-3 pr-4">
                                    <div class="flex items-start gap-3">
                                        {{-- Miniature volontairement petite (demande client) : juste assez
                                        pour identifier le film visuellement sans alourdir la ligne. --}}
                                        <a href="/cinema/films/{{ $movie?->slug }}" data-track="movie:{{ $movie?->id }}:cinema_salle"
                                           class="block aspect-[2/3] w-12 shrink-0 overflow-hidden rounded bg-ink-100">
                                            <x-ui.entity-image :src="$movie?->poster_url" :alt="$movieTitle" />
                                        </a>
                                        <div class="min-w-[10rem]">
                                            <a href="/cinema/films/{{ $movie?->slug }}" data-track="movie:{{ $movie?->id }}:cinema_salle"
                                               class="font-heading font-semibold text-ink-900 hover:text-brand-700">
                                                {{ $movieTitle }}
                                            </a>
                                            @if ($movie)
                                                <p class="mt-0.5 text-xs text-ink-500">
                                                    {{ collect([$movie->genres, $movie->duration_minutes ? $movie->duration_minutes.' min' : null, $movie->director, $movie->distributor])->filter()->implode(' · ') }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                @foreach ($weekDays as $day)
                                    <td class="px-2 py-3 text-center">
                                        <div class="flex flex-col items-center gap-1">
                                            {{-- Horaire cliquable vers la réservation sur le vrai site source
                                            (demande client — "2e scraping" du legacy, autoUpdateCinemaAllocineLiens/Liens2,
                                            voir docblock d'AllocineDriver::extractBookingUrl()) quand un lien a
                                            été capturé ET que la séance a encore une occurrence future
                                            (demande client, 22/09/2026 — voir x-cinema.screening-time) ;
                                            simple badge non cliquable sinon. --}}
                                            @foreach ($weekdayEntries[$movieTitle]->get($day->dayOfWeek, collect())->sortBy(fn ($entry) => $entry['time']->time) as $entry)
                                                <x-cinema.screening-time :screening="$entry['screening']" :time="$entry['time']" />
                                            @endforeach
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
