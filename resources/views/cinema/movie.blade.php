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
    // jour affiché sur notre front.
    $weekdays = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

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
                @if ($movie->poster_url)
                    <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" class="h-full w-full object-cover">
                @endif
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

        <h2 class="mt-10 font-heading text-xl font-bold text-ink-900">Séances</h2>
        @if ($screeningsByCinema->isEmpty())
            <p class="mt-3 text-ink-500">Aucune séance programmée actuellement.</p>
        @else
            <div class="mt-4 space-y-6">
                @foreach ($screeningsByCinema as $cinemaName => $screenings)
                    <div class="rounded-2xl border border-ink-100 bg-white p-5 shadow-sm">
                        <p class="font-heading font-semibold text-ink-900">{{ $cinemaName }}</p>
                        @foreach ($screenings as $screening)
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                @if ($screening->language)
                                    <x-ui.badge color="ink">{{ $screening->language->name }}</x-ui.badge>
                                @endif
                                @foreach ($screening->types as $type)
                                    <x-ui.badge>{{ $type->name }}</x-ui.badge>
                                @endforeach
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($screening->times as $time)
                                        {{-- Horaire cliquable vers la réservation sur le vrai site source
                                        (demande client — "2e scraping" du legacy, autoUpdateCinemaAllocineLiens/Liens2,
                                        voir docblock d'AllocineDriver::extractBookingUrl()) quand un lien a
                                        été capturé ; simple badge non cliquable sinon (pas de lien "default"
                                        renvoyé par AlloCiné pour cette séance). --}}
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
                                            <span class="rounded-lg bg-ink-50 px-2 py-1 text-ink-700" title="{{ $weekdays[$time->weekday] ?? '' }}">
                                                {{ $weekdays[$time->weekday] ?? '' }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        <h2 class="mt-10 font-heading text-xl font-bold text-ink-900">Avis ({{ $commentsCount }})</h2>

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
