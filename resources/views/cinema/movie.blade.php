@php
    // ⚠️ Bug réel trouvé et corrigé (01/09/2026, signalé par le client : lien
    // de réservation d'un horaire renvoyant vers le mauvais jour sur le site
    // d'origine). Confirmé via `old/backEnd/.../CinemaController.php` :
    // `jour = $date->format('w')` (PHP `date('w')` = 0 Dimanche…6 Samedi) et
    // la requête SQL historique (`jour = 0 as sunday`, `jour = 1 as monday`,
    // …) — convention aussi reprise, correctement, par `AllocineDriver`
    // (`$startsAt->dayOfWeek`, même échelle Carbon) et par
    // `ScreeningsRelationManager::WEEKDAYS` dans l'admin. Cette vue utilisait
    // un tableau Lundi=0…Dimanche=6 (jamais confirmé, voir ancien
    // commentaire) : chaque horaire était donc affiché avec le jour suivant
    // celui réellement scrapé (Lundi affiché pour un horaire réellement
    // scrapé un Dimanche, etc.) — d'où le lien de réservation (correct,
    // pointant vers le vrai jour scrapé) qui semblait "en décalage" avec le
    // jour affiché sur notre front. Le tableau des jours vit désormais dans
    // resources/views/components/cinema/screening-time.blade.php (partagé
    // avec cinema/salle.blade.php), pas ici.

    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Movie',
        'name' => $movie->title,
        'description' => $movie->synopsis,
        'image' => $movie->poster_url,
        'director' => $movie->director ? ['@type' => 'Person', 'name' => $movie->director] : null,
        'datePublished' => $movie->release_date?->toDateString(),
        'duration' => $movie->duration_minutes ? 'PT'.$movie->duration_minutes.'M' : null,
    ]);
@endphp

<x-layouts.app :seo="$seo">
    @push('head')
        <script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>
    @endpush

    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Cinéma', 'href' => '/cinema'], ['label' => $movie->title]]" />

        <div class="grid gap-8 sm:grid-cols-3">
            <div class="aspect-[2/3] overflow-hidden rounded-2xl bg-ink-100 sm:col-span-1">
                <x-ui.entity-image :src="$movie->poster_url" :alt="$movie->title" />
            </div>
            <div class="sm:col-span-2">
                <h1 class="font-heading text-3xl font-bold text-ink-900">{{ $movie->title }}</h1>
                <p class="mt-2 text-sm text-ink-500">
                    {{ collect([$movie->genres, $movie->duration_minutes ? $movie->duration_minutes.' min' : null, $movie->release_date?->translatedFormat('d M Y')])->filter()->implode(' · ') }}
                </p>
                {{-- Note moyenne (demande client, 19/09/2026 — bonne pratique
                des sites de cinéma) : uniquement si au moins un avis validé. --}}
                <x-ui.star-rating :rating="$averageRating" :count="$commentsCount" class="mt-2" />
                @if ($movie->director)
                    <p class="mt-1 text-sm text-ink-600">Réalisé par <strong>{{ $movie->director }}</strong></p>
                @endif
                @if ($movie->cast)
                    <p class="mt-1 text-sm text-ink-600">Avec {{ $movie->cast }}</p>
                @endif
                @if ($movie->synopsis)
                    <p class="mt-4 text-ink-700">{{ $movie->synopsis }}</p>
                @endif
            </div>
        </div>

        {{-- Ancre utilisée par /cinema/panorama ("Où voir ce film ?",
        demande client 05/10/2026) pour pointer directement sur cette
        section depuis la liste alphabétique. --}}
        <h2 id="seances" class="mt-10 font-heading text-xl font-bold text-ink-900">Séances</h2>
        @if ($screeningsByCinema->isEmpty())
            <p class="mt-3 text-ink-500">Aucune séance programmée actuellement.</p>
        @else
            {{-- Demande client (05/10/2026) : même présentation en tableau que
            /cinema/salles/{slug} (lignes = salles ici, colonnes = jours),
            plutôt qu'un bloc par salle avec les horaires en vrac. --}}
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[640px] border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 border border-brand-200 bg-brand-50 px-2 py-2 text-left font-heading text-ink-900 sm:px-3">Salle</th>
                            @foreach ($weekDays as $day)
                                <th class="border border-brand-200 bg-brand-50 px-2 py-2 text-center font-heading text-xs font-semibold uppercase text-ink-700">
                                    {{ $day->translatedFormat('D') }}<br>{{ $day->format('d/m') }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($screeningsByCinema as $cinemaName => $screenings)
                            @php
                                $cinema = $screenings->first()->cinema;
                                $rowBg = $loop->iteration % 2 === 0 ? 'bg-ink-50' : 'bg-white';
                                // Langues/types distincts toutes séances confondues pour cette
                                // salle (ex. VF ET VO du même film) — affichés une seule fois
                                // sous le nom de la salle plutôt que répétés par séance.
                                $badges = $screenings
                                    ->flatMap(fn ($s) => collect([$s->language?->name])->merge($s->types->pluck('name')))
                                    ->filter()
                                    ->unique();
                                $entriesByWeekday = $screenings
                                    ->flatMap(fn ($screening) => $screening->times->map(fn ($time) => ['screening' => $screening, 'time' => $time]))
                                    ->groupBy(fn ($entry) => (int) $entry['time']->weekday);
                            @endphp
                            <tr class="{{ $rowBg }} align-top">
                                <td class="sticky left-0 z-10 {{ $rowBg }} border border-ink-200 px-2 py-3 sm:px-3">
                                    <a href="/cinema/salles/{{ $cinema?->slug }}" data-track="cinema:{{ $cinema?->id }}:cinema_movie_seances"
                                       class="font-heading text-xs font-semibold text-ink-900 hover:text-brand-700 sm:text-sm">
                                        {{ $cinemaName }}
                                    </a>
                                    @if ($badges->isNotEmpty())
                                        <p class="mt-0.5 hidden text-xs text-ink-500 sm:block">{{ $badges->implode(' · ') }}</p>
                                    @endif
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
                                            @foreach ($entriesByWeekday->get($day->dayOfWeek, collect())->sortBy(fn ($entry) => $entry['time']->time) as $entry)
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

        {{-- Ancre utilisée par /cinema/panorama ("Avis", demande client
        05/10/2026) pour pointer directement sur cette section depuis la
        liste alphabétique. --}}
        <h2 id="avis" class="mt-10 font-heading text-xl font-bold text-ink-900">Avis ({{ $commentsCount }})</h2>

        @if ($movie->publishedComments->isNotEmpty())
            <div class="mt-4 space-y-4">
                @foreach ($movie->publishedComments as $comment)
                    <div class="rounded-xl border border-ink-100 bg-white p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-ink-800">{{ $comment->author_name }}</p>
                            @if ($comment->rating)
                                <x-ui.star-rating :rating="$comment->rating" />
                            @endif
                        </div>
                        <p class="mt-1 text-ink-700">{{ $comment->body }}</p>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-3 text-ink-500">Aucun avis pour le moment — soyez le premier à en laisser un.</p>
        @endif

        {{-- Formulaire d'avis (demande client, 19/09/2026) — anonyme +
        modération, même workflow que les autres formulaires publics du site
        (voir docblock de CinemaController::storeComment()) : le message de
        confirmation ("sera publié après validation") est affiché par le
        layout via session('status'), voir components/layouts/app.blade.php. --}}
        <div class="mt-8 rounded-2xl border border-ink-100 bg-white p-5 shadow-sm">
            <h3 class="font-heading text-lg font-semibold text-ink-900">Laisser un avis</h3>
            <form method="POST" action="{{ route('cinema.movie.comment', $movie->slug) }}" data-recaptcha-action="movie_comment" class="mt-4 space-y-4">
                @csrf

                {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label for="website">Laisser vide</label>
                    <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="author_name" class="block text-sm font-medium text-ink-700">Votre nom *</label>
                        <input type="text" name="author_name" id="author_name" required value="{{ old('author_name') }}"
                            class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                        <x-ui.field-error name="author_name" />
                    </div>
                    <div>
                        <label for="author_email" class="block text-sm font-medium text-ink-700">Votre email *</label>
                        <input type="email" name="author_email" id="author_email" required value="{{ old('author_email') }}"
                            class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                        <p class="mt-1 text-xs text-ink-500">Jamais affiché publiquement.</p>
                        <x-ui.field-error name="author_email" />
                    </div>
                </div>

                <div>
                    <label for="rating" class="block text-sm font-medium text-ink-700">Votre note *</label>
                    <select name="rating" id="rating" required
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                        <option value="">Choisir…</option>
                        @foreach ([5 => 'Excellent', 4 => 'Très bien', 3 => 'Bien', 2 => 'Moyen', 1 => 'Décevant'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('rating') == $value)>{{ str_repeat('★', $value).str_repeat('☆', 5 - $value) }} — {{ $label }}</option>
                        @endforeach
                    </select>
                    <x-ui.field-error name="rating" />
                </div>

                <div>
                    <label for="body" class="block text-sm font-medium text-ink-700">Votre avis *</label>
                    <textarea name="body" id="body" rows="4" required maxlength="2000"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('body') }}</textarea>
                    <x-ui.field-error name="body" />
                </div>

                <x-ui.button type="submit" variant="primary">Envoyer mon avis</x-ui.button>
            </form>
        </div>

        @if ($related->isNotEmpty())
            <div class="mt-12">
                <h2 class="font-heading text-lg font-semibold text-ink-900">Actuellement à l'affiche</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-ui.card
                            :href="'/cinema/films/'.$item->slug"
                            :image="$item->poster_url"
                            :title="$item->title"
                            :track="'movie:'.$item->id.':cinema_related'"
                        />
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
