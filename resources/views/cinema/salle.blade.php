@php
    $weekdays = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'MovieTheater',
        'name' => $cinema->name,
        'address' => $cinema->address,
        'url' => $cinema->external_url,
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
                                    <span class="rounded-lg bg-ink-50 px-2 py-1 text-ink-700">
                                        {{ $weekdays[$time->weekday] ?? '' }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}
                                    </span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
