<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="$category
            ? [['label' => 'Annuaire', 'href' => '/annuaire'], ['label' => $category->name]]
            : [['label' => 'Annuaire']]" />

        <div class="flex flex-col gap-8 lg:flex-row">
            {{-- Catégories --}}
            <aside class="lg:w-64 lg:shrink-0">
                <h2 class="font-heading text-lg font-bold text-ink-900">Catégories</h2>
                <ul class="mt-4 space-y-1 text-sm">
                    <li>
                        <a href="/annuaire" class="block rounded-lg px-3 py-2 {{ ! $category ? 'bg-brand-50 font-semibold text-brand-700' : 'text-ink-700 hover:bg-ink-50' }}">
                            Tout l'annuaire
                        </a>
                    </li>
                    @foreach ($topCategories as $top)
                        <li>
                            <a
                                href="/annuaire/{{ $top->slug }}"
                                data-track="category:{{ $top->id }}:annuaire_sidebar"
                                class="block rounded-lg px-3 py-2 {{ $category?->id === $top->id ? 'bg-brand-50 font-semibold text-brand-700' : 'text-ink-700 hover:bg-ink-50' }}"
                            >
                                {{ $top->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </aside>

            {{-- Résultats --}}
            <div class="flex-1">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                        {{ $category?->name ?? 'Annuaire de Toulouse' }}
                    </h1>
                    <form method="GET" class="flex gap-2">
                        <input
                            type="search" name="q" value="{{ request('q') }}"
                            placeholder="Rechercher une fiche…"
                            class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-64"
                        >
                        <x-ui.button type="submit" variant="outline" size="sm">Rechercher</x-ui.button>
                    </form>
                </div>

                @if ($category?->description)
                    <p class="mt-4 max-w-3xl text-ink-600">{{ $category->description }}</p>
                @endif

                @if ($listings->isEmpty())
                    <p class="mt-10 text-ink-500">Aucune fiche ne correspond à votre recherche pour le moment.</p>
                @else
                    <div class="mt-8 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($listings as $listing)
                            <x-ui.card
                                :href="'/annuaire/fiche/'.$listing->slug"
                                :image="$listing->getFirstMediaUrl('logo')"
                                :eyebrow="$listing->categories->first()?->name"
                                :title="$listing->title"
                                :meta="collect([$listing->city, $listing->phone])->filter()->implode(' · ')"
                                :track="'listing:'.$listing->id.':annuaire_listing'"
                            >
                                @if ($listing->isPaid())
                                    <x-ui.badge color="accent">Partenaire</x-ui.badge>
                                @endif
                            </x-ui.card>
                        @endforeach
                    </div>

                    <div class="mt-10">{{ $listings->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
