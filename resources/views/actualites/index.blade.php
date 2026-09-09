<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="$category
            ? [['label' => 'Actualités', 'href' => '/actualites'], ['label' => $category->name]]
            : [['label' => 'Actualités']]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                {{ $category?->name ?? 'Actualités de Toulouse' }}
            </h1>
            <div class="flex gap-2">
                <form method="GET" class="flex gap-2">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher…"
                        class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-64">
                    <x-ui.button type="submit" variant="outline" size="sm">Rechercher</x-ui.button>
                </form>
                <x-ui.button href="/actualites/proposer" variant="primary" size="sm">Proposer une actualité</x-ui.button>
            </div>
        </div>

        @if ($categories->isNotEmpty())
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="/actualites" class="rounded-full px-3 py-1.5 text-sm {{ ! $category ? 'bg-brand-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100' }}">Toutes</a>
                @foreach ($categories as $cat)
                    <a href="/actualites/{{ $cat->slug }}" class="rounded-full px-3 py-1.5 text-sm {{ $category?->id === $cat->id ? 'bg-brand-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100' }}">
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($news->isEmpty())
            <p class="mt-10 text-ink-500">Aucune actualité ne correspond à ces critères.</p>
        @else
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($news as $item)
                    <x-ui.card
                        :href="'/actualites/'.$item->slug"
                        :image="$item->image_url"
                        :eyebrow="$item->category?->name"
                        :title="$item->title"
                        :meta="$item->published_at?->translatedFormat('d M Y')"
                        :track="'news:'.$item->id.':actualites_listing'"
                    />
                @endforeach
            </div>

            <div class="mt-10">{{ $news->links() }}</div>
        @endif
    </div>
</x-layouts.app>
