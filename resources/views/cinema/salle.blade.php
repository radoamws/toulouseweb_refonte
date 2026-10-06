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

        {{-- Navigation semaine précédente/suivante (demande client, 05/10/2026)
        — toujours visible, même sans aucune séance cette semaine-là, pour
        pouvoir revenir en arrière sans modifier l'URL à la main. --}}
        <div class="mt-1 flex items-center justify-between gap-3">
            <a href="?week={{ $weekOffset - 1 }}" data-track="cinema:{{ $cinema->id }}:cinema_salle_week_prev" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-600 hover:bg-ink-50 hover:text-brand-700" aria-label="Semaine précédente">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 010 1.06L9.06 10l3.73 3.71a.75.75 0 11-1.06 1.06l-4.25-4.24a.75.75 0 010-1.06l4.25-4.24a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>
                <span class="hidden sm:inline">Semaine précédente</span>
            </a>
            <p class="text-center text-sm font-medium text-ink-500">
                Semaine du {{ $weekDays->first()->translatedFormat('d F') }} au {{ $weekDays->last()->translatedFormat('d F Y') }}
            </p>
            <a href="?week={{ $weekOffset + 1 }}" data-track="cinema:{{ $cinema->id }}:cinema_salle_week_next" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-600 hover:bg-ink-50 hover:text-brand-700" aria-label="Semaine suivante">
                <span class="hidden sm:inline">Semaine suivante</span>
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 010-1.06L10.94 10 7.21 6.29a.75.75 0 111.06-1.06l4.25 4.24a.75.75 0 010 1.06l-4.25 4.24a.75.75 0 01-1.06 0z" clip-rule="evenodd" /></svg>
            </a>
        </div>

        @if ($screeningsByMovie->isEmpty())
            <p class="mt-3 text-ink-500">Aucune séance programmée pour cette semaine.</p>
        @else
            {{-- Demande client (05/10/2026) : "j'avais accès à une grille jour
            par jour et non une liste de film qui ne sont pas placés en ordre
            chronologique [...] l'entrée par salle est plus pertinente" —
            restaure le tableau "1 film par ligne x 7 jours" de l'ancienne
            version du site (mercredi à mardi, ordre chronologique réel),
            avec une miniature compacte pour garder une hauteur de ligne
            raisonnable (demande explicite : pas de défilement excessif). --}}
            <div class="mt-4 overflow-x-auto">
                {{-- Demande client (05/10/2026, 2e retour) : "difficile à lire
                en alignement" — bordures de couleur sur chaque cellule +
                zébrage des lignes pour suivre facilement une ligne/colonne du
                regard. Colonne "Films" réduite en mobile (miniature plus
                petite, méta masquée) : sans ça le tableau des horaires
                n'était pas visible à l'écran, la colonne film à elle seule
                prenant toute la largeur utile du viewport. --}}
                <table class="w-full min-w-[640px] border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 border border-brand-200 bg-brand-50 px-2 py-2 text-left font-heading text-ink-900 sm:px-3">Films</th>
                            @foreach ($weekDays as $day)
                                <th class="border border-brand-200 bg-brand-50 px-2 py-2 text-center font-heading text-xs font-semibold uppercase text-ink-700">
                                    {{ $day->translatedFormat('D') }}<br>{{ $day->format('d/m') }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($screeningsByMovie as $movieTitle => $screenings)
                            @php
                                $movie = $screenings->first()->movie;
                                $rowBg = $loop->iteration % 2 === 0 ? 'bg-ink-50' : 'bg-white';
                            @endphp
                            <tr class="{{ $rowBg }} align-top">
                                <td class="sticky left-0 z-10 {{ $rowBg }} border border-ink-200 px-2 py-3 sm:px-3">
                                    <div class="flex items-start gap-2 sm:gap-3">
                                        {{-- Miniature volontairement petite (demande client) : juste assez
                                        pour identifier le film visuellement sans alourdir la ligne —
                                        encore réduite en mobile (demande client, 2e retour). --}}
                                        <a href="/cinema/films/{{ $movie?->slug }}" data-track="movie:{{ $movie?->id }}:cinema_salle"
                                           class="block aspect-[2/3] w-8 shrink-0 overflow-hidden rounded bg-ink-100 sm:w-12">
                                            <x-ui.entity-image :src="$movie?->poster_url" :alt="$movieTitle" />
                                        </a>
                                        <div class="min-w-[4.5rem] sm:min-w-[10rem]">
                                            <a href="/cinema/films/{{ $movie?->slug }}" data-track="movie:{{ $movie?->id }}:cinema_salle"
                                               class="font-heading text-xs font-semibold text-ink-900 hover:text-brand-700 sm:text-sm">
                                                {{ $movieTitle }}
                                            </a>
                                            @if ($movie)
                                                <p class="mt-0.5 hidden text-xs text-ink-500 sm:block">
                                                    {{ collect([$movie->genres, $movie->duration_minutes ? $movie->duration_minutes.' min' : null, $movie->director, $movie->distributor])->filter()->implode(' · ') }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                @foreach ($weekDays as $day)
                                    <td class="border border-ink-200 px-2 py-3 text-center">
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
