<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Cinéma', 'href' => '/cinema'], ['label' => 'Panorama']]" />

        <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Panorama des films à Toulouse</h1>
            <p class="text-sm font-medium text-ink-500">
                Du {{ $weekDays->first()->translatedFormat('d F Y') }} au {{ $weekDays->last()->translatedFormat('d F Y') }}
            </p>
        </div>
        <p class="mt-2 text-ink-600">Tous les films actuellement à l'affiche, par ordre alphabétique.</p>

        @if ($movies->isEmpty())
            <p class="mt-10 text-ink-500">Aucun film à l'affiche pour le moment.</p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[480px] border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="border border-brand-200 bg-brand-50 px-3 py-2 text-left font-heading text-ink-900">Film</th>
                            <th class="border border-brand-200 bg-brand-50 px-3 py-2 text-left font-heading text-ink-900">Avis</th>
                            <th class="border border-brand-200 bg-brand-50 px-3 py-2 text-left font-heading text-ink-900">Où voir ce film ?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($movies as $movie)
                            <tr class="{{ $loop->even ? 'bg-ink-50' : 'bg-white' }}">
                                <td class="border border-ink-200 px-3 py-2">
                                    <a href="/cinema/films/{{ $movie->slug }}" data-track="movie:{{ $movie->id }}:cinema_panorama" class="font-medium text-ink-900 hover:text-brand-700">
                                        {{ $movie->title }}
                                    </a>
                                </td>
                                <td class="border border-ink-200 px-3 py-2">
                                    <a href="/cinema/films/{{ $movie->slug }}#avis" class="text-ink-600 hover:text-brand-700 hover:underline">
                                        Avis ({{ $movie->published_comments_count }})
                                    </a>
                                </td>
                                <td class="border border-ink-200 px-3 py-2">
                                    <a href="/cinema/films/{{ $movie->slug }}#seances" class="text-ink-600 hover:text-brand-700 hover:underline">
                                        Où voir ce film ?
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-10">{{ $movies->links() }}</div>
        @endif
    </div>
</x-layouts.app>
