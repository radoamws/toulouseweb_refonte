<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="$category
            ? [['label' => 'Agenda', 'href' => '/agenda'], ['label' => $category->name]]
            : [['label' => 'Agenda']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                {{ $category?->name ?? 'Agenda de Toulouse' }}
            </h1>

            <form method="GET" class="flex flex-wrap gap-2">
                <input type="hidden" name="view" value="{{ $view }}">
                <input type="date" name="date" value="{{ $date?->format('Y-m-d') }}"
                    class="rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher…"
                    class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-56">
                <x-ui.button type="submit" variant="outline" size="sm">Filtrer</x-ui.button>
                @if ($date || request('q'))
                    <x-ui.button :href="url()->current()" variant="ghost" size="sm">Réinitialiser</x-ui.button>
                @endif
            </form>
        </div>

        {{-- Bascule liste / calendrier (brief §6, "calendrier visuel") --}}
        <div class="mt-4 inline-flex rounded-full bg-ink-50 p-1 text-sm">
            <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"
                class="rounded-full px-4 py-1.5 font-medium {{ $view === 'list' ? 'bg-white text-brand-700 shadow-sm' : 'text-ink-600 hover:text-ink-900' }}">
                Liste
            </a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar']) }}"
                class="rounded-full px-4 py-1.5 font-medium {{ $view === 'calendar' ? 'bg-white text-brand-700 shadow-sm' : 'text-ink-600 hover:text-ink-900' }}">
                Calendrier
            </a>
        </div>

        {{-- Navigation jour précédent/suivant (brief §6 : "simple et rapide") --}}
        @if ($date)
            <div class="mt-4 flex items-center gap-3 text-sm">
                <x-ui.button :href="request()->fullUrlWithQuery(['date' => $date->copy()->subDay()->format('Y-m-d')])" variant="ghost" size="sm">&larr; Veille</x-ui.button>
                <span class="font-medium text-ink-700">{{ $date->translatedFormat('l d F Y') }}</span>
                <x-ui.button :href="request()->fullUrlWithQuery(['date' => $date->copy()->addDay()->format('Y-m-d')])" variant="ghost" size="sm">Lendemain &rarr;</x-ui.button>
            </div>
        @endif

        {{-- Catégories --}}
        <div class="mt-6 flex flex-wrap gap-2">
            <a href="/agenda?view={{ $view }}" class="rounded-full px-3 py-1.5 text-sm {{ ! $category ? 'bg-brand-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100' }}">Toutes</a>
            @foreach ($categories as $cat)
                <a href="/agenda/{{ $cat->slug }}?view={{ $view }}" class="rounded-full px-3 py-1.5 text-sm {{ $category?->id === $cat->id ? 'bg-brand-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100' }}">
                    {{ $cat->name }}
                </a>
            @endforeach
        </div>

        @if ($view === 'calendar')
            @include('agenda.partials.calendar')
        @endif

        @if ($events->isEmpty())
            <p class="mt-10 text-ink-500">Aucun événement ne correspond à ces critères.</p>
        @else
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($events as $event)
                    <x-ui.card
                        :href="'/agenda/'.$event->slug"
                        :image="$event->image_url"
                        :eyebrow="$event->categories->first()?->name"
                        :title="$event->title"
                        :meta="$event->start_date->translatedFormat('d M Y').($event->area ? ' · '.$event->area->name : '')"
                        :track="'event:'.$event->id.':agenda_listing'"
                    />
                @endforeach
            </div>

            <div class="mt-10">{{ $events->links() }}</div>
        @endif
    </div>
</x-layouts.app>
