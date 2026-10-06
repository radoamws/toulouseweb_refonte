<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Cinéma', 'href' => '/cinema'], ['label' => 'Panorama']]" />

        <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Panorama des films à Toulouse</h1>
        <p class="mt-2 text-ink-600">Tous les films à l'affiche cette semaine-là, par ordre alphabétique.</p>

        {{-- Navigation semaine précédente/suivante (demande client,
        05/10/2026), même mécanique que /cinema/salles/{slug} — pas de
        `data-track` ici : cette page n'est rattachée à aucune salle/film en
        particulier (voir resources/js/track-click.js, le format exige un
        entity_id). --}}
        <div class="mt-4 flex items-center justify-between gap-3">
            <a href="?week={{ $weekOffset - 1 }}" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-600 hover:bg-ink-50 hover:text-brand-700" aria-label="Semaine précédente">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 010 1.06L9.06 10l3.73 3.71a.75.75 0 11-1.06 1.06l-4.25-4.24a.75.75 0 010-1.06l4.25-4.24a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>
                <span class="hidden sm:inline">Semaine précédente</span>
            </a>
            <p class="text-center text-sm font-medium text-ink-500">
                Semaine du {{ $weekDays->first()->translatedFormat('d F') }} au {{ $weekDays->last()->translatedFormat('d F Y') }}
            </p>
            <a href="?week={{ $weekOffset + 1 }}" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-600 hover:bg-ink-50 hover:text-brand-700" aria-label="Semaine suivante">
                <span class="hidden sm:inline">Semaine suivante</span>
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 010-1.06L10.94 10 7.21 6.29a.75.75 0 111.06-1.06l4.25 4.24a.75.75 0 010 1.06l-4.25 4.24a.75.75 0 01-1.06 0z" clip-rule="evenodd" /></svg>
            </a>
        </div>

        @if ($movies->isEmpty())
            <p class="mt-10 text-ink-500">Aucun film à l'affiche pour cette semaine-là.</p>
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
