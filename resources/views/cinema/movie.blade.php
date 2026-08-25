@php
    // Convention reprise du legacy (t_cine_proj_heures.jour, 0-6) — non
    // documentée côté ancien système, hypothèse Lundi=0…Dimanche=6 à
    // confirmer (voir TECHNICAL_DOCUMENTATION.md §13).
    $weekdays = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

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
                                        <span class="rounded-lg bg-ink-50 px-2 py-1 text-ink-700" title="{{ $weekdays[$time->weekday] ?? '' }}">
                                            {{ $weekdays[$time->weekday] ?? '' }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @if ($movie->comments->isNotEmpty())
            <h2 class="mt-10 font-heading text-xl font-bold text-ink-900">Avis</h2>
            <div class="mt-4 space-y-4">
                @foreach ($movie->comments as $comment)
                    <div class="rounded-xl border border-ink-100 bg-white p-4">
                        <p class="text-sm font-semibold text-ink-800">{{ $comment->author_name }}</p>
                        <p class="mt-1 text-ink-700">{{ $comment->body }}</p>
                    </div>
                @endforeach
            </div>
        @endif

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
