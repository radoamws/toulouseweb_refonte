<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-[96rem] px-4 py-10 sm:px-6 lg:px-6">
        <x-ui.breadcrumb :items="[['label' => 'Cinéma']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Cinéma à Toulouse</h1>
            <form method="GET" class="flex gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher un film…"
                    class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-64">
                <x-ui.button type="submit" variant="outline" size="sm">Rechercher</x-ui.button>
            </form>
        </div>

        {{-- Demande client (05/10/2026) : "l'entrée par salle est plus
        pertinente que l'entrée par film car d'un seul coup d'oeil on peut se
        déterminer" — restaure les 2 colonnes "Toulouse et complexes"/"Toiles
        de banlieues" de l'ancienne version du site comme contenu principal
        de la page (avant la grille de films), chaque salle gardant son
        `data-track` (demande client du 15/09/2026 — suivi dans les stats
        admin, non régressé par ce changement). --}}
        @if ($complexeCinemas->isNotEmpty() || $banlieueCinemas->isNotEmpty())
            <div class="mt-8 grid gap-6 sm:grid-cols-2">
                @foreach ([['label' => 'Toulouse et complexes', 'cinemas' => $complexeCinemas], ['label' => 'Toiles de banlieues', 'cinemas' => $banlieueCinemas]] as $column)
                    @if ($column['cinemas']->isNotEmpty())
                        <div>
                            <h2 class="font-heading text-sm font-bold uppercase tracking-wide text-brand-700">{{ $column['label'] }}</h2>
                            <div class="mt-3 space-y-2">
                                @foreach ($column['cinemas'] as $cinema)
                                    <a href="/cinema/salles/{{ $cinema->slug }}" data-track="cinema:{{ $cinema->id }}:cinema_listing"
                                       class="block rounded-xl border border-ink-100 bg-white px-4 py-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                                        <p class="font-heading font-semibold text-ink-900">{{ $cinema->name }}</p>
                                        @if ($cinema->address)
                                            <p class="mt-0.5 text-sm text-ink-500">{{ $cinema->address }}</p>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- "Panorama" de la semaine (demande client, 19/09/2026) — entre la
        liste des salles et la liste des films, semaine cinéma (mercredi à
        mardi, sortie des films en France) calculée automatiquement, voir
        CinemaController::index(). --}}
        <div class="mt-10 flex flex-col gap-2 border-b border-ink-100 pb-3 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="font-heading text-xl font-bold text-brand-700 sm:text-2xl">
                <span class="inline-block rounded-lg bg-brand-50 px-3 py-1">Panorama</span>
            </h2>
            <p class="text-sm font-medium text-ink-500">
                Films à l'affiche du {{ $weekStart->translatedFormat('d F Y') }} au {{ $weekEnd->translatedFormat('d F Y') }}
            </p>
        </div>

        {{-- "Les plus commentés" (demande client, 19/09/2026 — bonne
        pratique des sites de cinéma type AlloCiné) : uniquement les films
        ayant au moins un avis validé, voir CinemaController::index(). --}}
        @if ($mostCommented->isNotEmpty())
            <div class="mt-8">
                <h3 class="font-heading text-base font-semibold text-ink-900">Les plus commentés</h3>
                <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($mostCommented as $movie)
                        <x-ui.card
                            :href="'/cinema/films/'.$movie->slug"
                            :image="$movie->poster_url"
                            :title="$movie->title"
                            :track="'movie:'.$movie->id.':cinema_most_commented'"
                        >
                            <x-ui.star-rating :rating="$movie->published_comments_avg_rating" :count="$movie->published_comments_count" />
                        </x-ui.card>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($movies->isEmpty())
            <p class="mt-10 text-ink-500">Aucun film à l'affiche pour le moment.</p>
        @else
            <h3 class="mt-10 font-heading text-base font-semibold text-ink-900">Tous les films à l'affiche</h3>
            <div class="mt-3 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($movies as $movie)
                    <a href="/cinema/films/{{ $movie->slug }}" data-track="movie:{{ $movie->id }}:cinema_listing"
                       class="group block overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="aspect-[2/3] w-full overflow-hidden bg-ink-100">
                            <x-ui.entity-image :src="$movie->poster_url" :alt="$movie->title" class="transition group-hover:scale-105" />
                        </div>
                        <p class="p-2 text-sm font-medium text-ink-800 group-hover:text-brand-700">{{ $movie->title }}</p>
                    </a>
                @endforeach
            </div>

            <div class="mt-10">{{ $movies->links() }}</div>
        @endif
    </div>
</x-layouts.app>
