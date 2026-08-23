<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="$category
            ? [['label' => 'Annonces', 'href' => '/annonces'], ['label' => $category->name]]
            : [['label' => 'Annonces']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                {{ $category?->name ?? 'Petites annonces' }}
            </h1>
            <div class="flex gap-2">
                <form method="GET" class="flex gap-2">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher…"
                        class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-56">
                    <x-ui.button type="submit" variant="outline" size="sm">Rechercher</x-ui.button>
                </form>
                <x-ui.button href="/annonces/deposer" variant="primary" size="sm">Déposer une annonce</x-ui.button>
            </div>
        </div>

        @if ($categories->isNotEmpty())
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="/annonces" class="rounded-full px-3 py-1.5 text-sm {{ ! $category ? 'bg-brand-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100' }}">Toutes</a>
                @foreach ($categories as $cat)
                    <a href="/annonces/{{ $cat->slug }}" class="rounded-full px-3 py-1.5 text-sm {{ $category?->id === $cat->id ? 'bg-brand-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100' }}">
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($classifieds->isEmpty())
            <p class="mt-10 text-ink-500">Aucune annonce publiée pour le moment.
                <a href="/annonces/deposer" class="font-semibold text-brand-700 hover:underline">Soyez le premier à en déposer une.</a>
            </p>
        @else
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($classifieds as $classified)
                    <x-ui.card
                        :href="'/annonces/'.$classified->slug"
                        :eyebrow="$classified->category?->name"
                        :title="$classified->title"
                        :meta="$classified->price ? number_format($classified->price, 0, ',', ' ').' €' : null"
                        :track="'classified:'.$classified->id.':annonces_listing'"
                    >
                        @if ($classified->is_featured)
                            <x-ui.badge color="accent">Mise en avant</x-ui.badge>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>

            <div class="mt-10">{{ $classifieds->links() }}</div>
        @endif
    </div>
</x-layouts.app>
