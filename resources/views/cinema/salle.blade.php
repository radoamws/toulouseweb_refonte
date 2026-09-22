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
                    @php $movie = $screenings->first()->movie; @endphp
                    {{-- Demande client (14/09/2026) : affichage plus attirant — l'affiche du
                    film sous son titre, et les horaires (l'info la plus consultée) dans une
                    colonne large à gauche plutôt qu'un simple bloc de texte pleine largeur. --}}
                    <div class="rounded-2xl border border-ink-100 bg-white p-5 shadow-sm">
                        {{-- Bug réel trouvé et corrigé (15/09/2026, demande client) : ni ce
                        lien ni celui de l'affiche ci-dessous n'étaient suivis — naviguer
                        vers une fiche film depuis une salle n'apparaissait jamais dans les
                        stats de l'admin. --}}
                        <a href="/cinema/films/{{ $movie?->slug }}" data-track="movie:{{ $movie?->id }}:cinema_salle" class="block font-heading font-semibold text-ink-900 hover:text-brand-700">
                            {{ $movieTitle }}
                        </a>
                        <div class="mt-3 sm:flex sm:items-start sm:gap-6">
                            <div class="sm:order-1 sm:flex-1">
                                @foreach ($screenings as $screening)
                                    <div class="flex flex-wrap gap-2 text-sm">
                                        {{-- Horaire cliquable vers la réservation sur le vrai site source
                                        (demande client — "2e scraping" du legacy, autoUpdateCinemaAllocineLiens/Liens2,
                                        voir docblock d'AllocineDriver::extractBookingUrl()) quand un lien a
                                        été capturé ET que la séance a encore une occurrence future
                                        (demande client, 22/09/2026 — voir x-cinema.screening-time) ;
                                        simple badge non cliquable sinon. --}}
                                        @foreach ($screening->times as $time)
                                            <x-cinema.screening-time :screening="$screening" :time="$time" />
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                            @if ($movie?->poster_url)
                                <div class="mt-4 shrink-0 sm:order-2 sm:mt-0 sm:w-32">
                                    <a href="/cinema/films/{{ $movie->slug }}" data-track="movie:{{ $movie->id }}:cinema_salle" class="block overflow-hidden rounded-lg bg-ink-100">
                                        <img
                                            src="{{ $movie->poster_url }}"
                                            alt="{{ $movieTitle }}"
                                            loading="lazy"
                                            class="aspect-[2/3] w-full object-cover transition hover:scale-105"
                                        >
                                    </a>
                                </div>
                            @endif
                        </div>
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
