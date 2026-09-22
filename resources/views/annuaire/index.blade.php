<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        @php
            // Fil d'ariane complet (demande client, 19/09/2026) : jusqu'ici
            // limité à "Annuaire > {catégorie}" même pour une sous-rubrique à
            // 2 niveaux (ex. Restaurants > Restaurant spectacle) — on
            // remonte la chaîne de parents pour l'afficher en entier.
            $ancestors = [];
            for ($node = $category; $node; $node = $node->parent) {
                array_unshift($ancestors, $node);
            }
            $breadcrumbItems = [['label' => 'Annuaire', 'href' => '/annuaire']];
            foreach ($ancestors as $i => $ancestor) {
                $isLast = $i === count($ancestors) - 1;
                $breadcrumbItems[] = $isLast ? ['label' => $ancestor->name] : ['label' => $ancestor->name, 'href' => '/annuaire/'.$ancestor->slug];
            }
        @endphp
        <x-ui.breadcrumb :items="$breadcrumbItems" />

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
                            {{-- Actif dès qu'on est sur cette rubrique OU une
                            de ses sous-rubriques (topCategory), pas
                            seulement une correspondance exacte. --}}
                            <a
                                href="/annuaire/{{ $top->slug }}"
                                data-track="category:{{ $top->id }}:annuaire_sidebar"
                                class="block rounded-lg px-3 py-2 {{ $topCategory?->id === $top->id ? 'bg-brand-50 font-semibold text-brand-700' : 'text-ink-700 hover:bg-ink-50' }}"
                            >
                                {{ $top->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="/annuaire/deposer" class="mt-6 block rounded-lg border border-dashed border-brand-300 px-3 py-2 text-center text-sm font-semibold text-brand-700 hover:bg-brand-50">
                    + Ajouter mon établissement
                </a>
            </aside>

            {{-- Résultats --}}
            <div class="flex-1">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                        {{ $category?->name ?? 'Annuaire de Toulouse' }}
                    </h1>
                    <form method="GET" class="flex flex-wrap gap-2">
                        <input
                            type="search" name="q" value="{{ request('q') }}"
                            placeholder="Rechercher une fiche…"
                            class="w-full rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-56"
                        >
                        {{-- Recherche géographique (brief §5) : filtre par ville — voir
                        ListingController::renderIndex() pour la limite connue
                        (pas de vraies coordonnées lat/lng côté legacy). --}}
                        <select name="city" class="rounded-full border border-ink-200 px-4 py-2 text-sm focus:border-brand-500 focus:outline-none">
                            <option value="">Toutes les villes</option>
                            @foreach ($cities as $c)
                                <option value="{{ $c }}" @selected(request('city') === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                        <x-ui.button type="submit" variant="outline" size="sm">Rechercher</x-ui.button>
                        @if (request('q') || request('city'))
                            <x-ui.button :href="url()->current()" variant="ghost" size="sm">Réinitialiser</x-ui.button>
                        @endif
                    </form>
                </div>

                {{-- `$category->description` (demande client, 22/09/2026 :
                "cacher les descriptions longues entre le titre de la
                catégorie et le sous-menu") volontairement PAS affichée ici :
                c'est du texte de bourrage de mots-clés SEO hérité du legacy
                (ex. "a emporter toulouse, a emporter, toulouse a emporter,
                emporter toulouse..."), jamais pensé pour être lu par un
                visiteur. Le champ reste utilisé tel quel pour le <meta
                name="description"> (voir SeoResolverService::generateDescription()),
                seul son affichage en corps de page est retiré ici. --}}

                {{-- Sous-rubriques (demande client, 19/09/2026) — ex.
                Restaurants : Restaurant spectacle, Guinguettes,
                Pizzerias... Toujours les sous-rubriques du TOP niveau
                (topCategory), pas seulement celles de la catégorie en
                cours, pour pouvoir naviguer d'une sous-rubrique à l'autre. --}}
                @if ($subCategories->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a
                            href="/annuaire/{{ $topCategory->slug }}"
                            class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $category?->id === $topCategory->id ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' }}"
                        >Toutes</a>
                        @foreach ($subCategories as $sub)
                            <a
                                href="/annuaire/{{ $sub->slug }}"
                                data-track="category:{{ $sub->id }}:annuaire_subcategory"
                                class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $category?->id === $sub->id ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' }}"
                            >{{ $sub->name }}</a>
                        @endforeach
                    </div>
                @endif

                @if ($listings->isEmpty())
                    <p class="mt-10 text-ink-500">
                        Aucune fiche ne correspond à votre recherche pour le moment.
                        <a href="/annuaire/deposer" class="font-semibold text-brand-700 hover:underline">Ajoutez la vôtre.</a>
                    </p>
                @else
                    <div class="mt-8 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($listings as $listing)
                            <x-ui.card
                                :href="'/annuaire/fiche/'.$listing->slug"
                                :image="$listing->getFirstMediaUrl('logo')"
                                :eyebrow="$listing->categories->first()?->name"
                                :title="$listing->title"
                                {{-- clean_phone, pas phone (bug réel corrigé le 08/09/2026, audit SEO final) — voir Listing::cleanPhone(). --}}
                                :meta="collect([$listing->city, $listing->clean_phone])->filter()->implode(' · ')"
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
