<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Cinéma']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Cinéma à Toulouse</h1>
            <form method="GET" class="flex gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher un film…"
                    class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-64">
                <x-ui.button type="submit" variant="outline" size="sm">Rechercher</x-ui.button>
            </form>
        </div>

        @if ($cinemas->isNotEmpty())
            <div class="mt-6 flex flex-wrap gap-2">
                @foreach ($cinemas as $cinema)
                    <a href="/cinema/salles/{{ $cinema->slug }}" class="rounded-full bg-ink-50 px-3 py-1.5 text-sm text-ink-700 hover:bg-ink-100">
                        {{ $cinema->name }}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($movies->isEmpty())
            <p class="mt-10 text-ink-500">Aucun film à l'affiche pour le moment.</p>
        @else
            <div class="mt-8 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($movies as $movie)
                    <a href="/cinema/films/{{ $movie->slug }}" data-track="movie:{{ $movie->id }}:cinema_listing"
                       class="group block overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="aspect-[2/3] w-full overflow-hidden bg-ink-100">
                            @if ($movie->poster_url)
                                <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" loading="lazy" class="h-full w-full object-cover transition group-hover:scale-105">
                            @endif
                        </div>
                        <p class="p-2 text-sm font-medium text-ink-800 group-hover:text-brand-700">{{ $movie->title }}</p>
                    </a>
                @endforeach
            </div>

            <div class="mt-10">{{ $movies->links() }}</div>
        @endif
    </div>
</x-layouts.app>
