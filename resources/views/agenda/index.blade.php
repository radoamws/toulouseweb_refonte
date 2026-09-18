<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="$category
            ? [['label' => 'Agenda', 'href' => '/agenda'], ['label' => $category->name]]
            : [['label' => 'Agenda']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                {{ $category?->name ?? 'Agenda de Toulouse' }}
            </h1>
            <a href="/agenda/proposer" class="inline-block w-fit shrink-0 rounded-full border border-dashed border-brand-300 px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">
                + Proposer un événement
            </a>
        </div>

        {{-- Catégories — chaque rubrique a sa propre couleur (demande client,
        18/09/2026), reprise sur les fiches ci-dessous pour les distinguer
        d'un coup d'œil. Couleur portée par un point + une bordure à l'état
        actif, jamais par tout le fond du bouton (texte toujours lisible,
        quelle que soit la couleur — certaines catégories ont des couleurs
        très claires). --}}
        @php
            // `category` est un SEGMENT de route (/agenda/{slug}), pas un
            // paramètre de requête — impossible d'utiliser fullUrlWithQuery()
            // pour en changer, contrairement à date/area/q. On reconstruit
            // donc la query string à préserver (date/area/q) une seule fois
            // ici, réutilisée pour "Toutes" et chaque catégorie.
            $preservedQuery = http_build_query(array_filter(request()->only(['date', 'area', 'q'])));
        @endphp
        <div class="mt-6 flex flex-wrap gap-2">
            <a
                href="/agenda{{ $preservedQuery ? '?'.$preservedQuery : '' }}"
                class="rounded-full border px-3 py-1.5 text-sm font-medium {{ ! $category ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' }}"
            >Toutes</a>
            @foreach ($categories as $cat)
                <a
                    href="/agenda/{{ $cat->slug }}{{ $preservedQuery ? '?'.$preservedQuery : '' }}"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition
                        {{ $category?->id === $cat->id ? 'text-white' : 'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' }}"
                    @style([
                        "border-color: {$cat->color}",
                        "background-color: {$cat->color}" => $category?->id === $cat->id,
                    ])
                >
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $category?->id === $cat->id ? 'bg-white/80' : '' }}" @style(["background-color: {$cat->color}" => $category?->id !== $cat->id])></span>
                    {{ $cat->name }}
                </a>
            @endforeach
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-[280px_1fr]">
            {{-- Colonne latérale : calendrier compact + filtres (demande
            client, 18/09/2026 — "le calendrier doit être sur la liste et en
            petit", recherche et filtre par lieu conservés à côté). --}}
            <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                @include('agenda.partials.calendar')

                <form method="GET" class="space-y-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-sm">
                    <input type="hidden" name="date" value="{{ $date?->format('Y-m-d') }}">
                    <div>
                        <label for="q" class="block text-xs font-semibold uppercase tracking-wide text-ink-400">Rechercher</label>
                        <input type="search" name="q" id="q" value="{{ request('q') }}" placeholder="Nom de l'événement…"
                            class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    </div>
                    {{-- Filtre par lieu (demande client, 18/09/2026) — seuls
                    les lieux ayant au moins un événement publié sont
                    proposés, voir le docblock d'EventController::renderIndex(). --}}
                    @if ($areas->isNotEmpty())
                        <div>
                            <label for="area" class="block text-xs font-semibold uppercase tracking-wide text-ink-400">Lieu</label>
                            <select name="area" id="area" class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                                <option value="">Tous les lieux</option>
                                @foreach ($areas as $item)
                                    <option value="{{ $item->id }}" @selected($area?->id === $item->id)>{{ $item->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <x-ui.button type="submit" variant="outline" size="sm" class="w-full !justify-center">Filtrer</x-ui.button>
                    @if ($date || $area || request('q'))
                        <x-ui.button :href="$category ? '/agenda/'.$category->slug : '/agenda'" variant="ghost" size="sm" class="w-full !justify-center">Réinitialiser</x-ui.button>
                    @endif
                </form>
            </aside>

            <div>
                {{-- Navigation jour précédent/suivant (brief §6 : "simple et rapide") --}}
                @if ($date)
                    <div class="mb-6 flex items-center gap-3 text-sm">
                        <x-ui.button :href="request()->fullUrlWithQuery(['date' => $date->copy()->subDay()->format('Y-m-d')])" variant="ghost" size="sm">&larr; Veille</x-ui.button>
                        <span class="font-medium text-ink-700">{{ $date->translatedFormat('l d F Y') }}</span>
                        <x-ui.button :href="request()->fullUrlWithQuery(['date' => $date->copy()->addDay()->format('Y-m-d')])" variant="ghost" size="sm">Lendemain &rarr;</x-ui.button>
                    </div>
                @endif

                @if ($events->isEmpty())
                    <p class="text-ink-500">Aucun événement ne correspond à ces critères.</p>
                @else
                    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($events as $event)
                            @php $eventCategory = $event->categories->first(); @endphp
                            {{-- Bordure gauche colorée + point de couleur (demande
                            client, 18/09/2026) : identifie la rubrique d'un coup
                            d'œil, cohérent avec la couleur des pastilles ci-dessus. --}}
                            <a
                                href="/agenda/{{ $event->slug }}"
                                data-track="event:{{ $event->id }}:agenda_listing"
                                class="group flex flex-col overflow-hidden rounded-2xl border border-l-4 border-ink-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                @style(["border-left-color: {$eventCategory?->color}" => $eventCategory])
                            >
                                <div class="aspect-[4/3] w-full overflow-hidden bg-ink-100">
                                    @if ($event->image_url)
                                        <img src="{{ $event->image_url }}" alt="{{ $event->title }}" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center text-ink-300">
                                            <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 4.5h18M3 19.5h18M4.5 4.5v15m15-15v15" />
                                            </svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex flex-1 flex-col gap-2 p-4">
                                    @if ($eventCategory)
                                        <span
                                            class="inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide"
                                            style="background-color: color-mix(in srgb, {{ $eventCategory->color }} 15%, white); color: {{ $eventCategory->color }};"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $eventCategory->color }};"></span>
                                            {{ $eventCategory->name }}
                                        </span>
                                    @endif
                                    <h3 class="font-heading text-base font-semibold leading-snug text-ink-900 group-hover:text-brand-700">
                                        {{ $event->title }}
                                    </h3>
                                    {{-- Début ET fin de l'événement (demande client, 18/09/2026). --}}
                                    <p class="mt-auto text-sm text-ink-500">
                                        {{ $event->event_date_range }}{{ $event->area ? ' · '.$event->area->name : '' }}
                                    </p>
                                </div>
                            </a>
                        @endforeach
                    </div>

                    <div class="mt-10">{{ $events->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
