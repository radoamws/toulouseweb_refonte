<x-layouts.app :seo="$seo">
    {{-- Bug réel trouvé et corrigé (08/09/2026, audit SEO final,
    TECHNICAL_DOCUMENTATION.md §24) : la home n'avait AUCUN <h1> (confirmé en
    direct, 0 occurrence) — chaque section utilise un <h2> (x-ui.section-heading),
    le slide du hero un simple <p>. `sr-only` (même pattern déjà utilisé pour
    le lien d'évitement plus bas dans le layout) : signal SEO/accessibilité
    sans dicter de choix visuel qui n'est pas prévu ici (le logo du header
    porte déjà la marque visuellement). --}}
    <h1 class="sr-only">{{ \App\Models\SiteSetting::current()->site_name }} — Toulouse et sa région</h1>

    <x-site.hero-slider :slides="$slides" />

    <div class="mx-auto max-w-7xl space-y-16 px-4 py-12 sm:px-6 lg:px-8 lg:py-16">

        {{-- Catégories importantes de l'annuaire --}}
        @if ($topCategories->isNotEmpty())
            <section aria-label="Catégories populaires">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    @foreach ($topCategories as $category)
                        <a
                            href="/annuaire/{{ $category->slug }}"
                            data-track="category:{{ $category->id }}:homepage_grid"
                            class="flex flex-col items-center gap-2 rounded-2xl border border-ink-100 bg-white p-4 text-center shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                                @if ($category->icon_url)
                                    {{-- Icône définie dans l'admin (CategoryResource, brief) — repli sur
                                    l'icône générique par défaut ci-dessous si non renseignée. --}}
                                    <img src="{{ $category->icon_url }}" alt="" class="h-5 w-5 object-contain" loading="lazy">
                                @else
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 1015 0 7.5 7.5 0 00-15 0z" />
                                    </svg>
                                @endif
                            </span>
                            <span class="text-sm font-medium text-ink-800">{{ $category->name }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Actualités --}}
        @if ($latestNews->isNotEmpty())
            <section aria-labelledby="news-heading">
                <x-ui.section-heading eyebrow="À la une" href="/actualites">Actualités de Toulouse</x-ui.section-heading>
                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($latestNews as $news)
                        <x-ui.card
                            :href="'/actualites/'.$news->slug"
                            :image="$news->image_url"
                            :eyebrow="$news->category?->name"
                            :title="$news->title"
                            :meta="$news->event_date_range ?? $news->published_at?->translatedFormat('d M Y')"
                            :track="'news:'.$news->id.':homepage_news'"
                        />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Agenda --}}
        @if ($upcomingEvents->isNotEmpty())
            <section aria-labelledby="agenda-heading">
                <x-ui.section-heading eyebrow="À ne pas manquer" href="/agenda">Agenda &amp; sorties</x-ui.section-heading>
                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($upcomingEvents as $event)
                        <x-ui.card
                            :href="'/agenda/'.$event->slug"
                            :image="$event->image_url"
                            :eyebrow="$event->categories->first()?->name"
                            :title="$event->title"
                            :meta="$event->start_date->translatedFormat('d M Y').($event->area ? ' · '.$event->area->name : '')"
                            :track="'event:'.$event->id.':homepage_agenda'"
                        />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Cinéma --}}
        @if ($latestMovies->isNotEmpty())
            <section aria-labelledby="cinema-heading">
                <x-ui.section-heading eyebrow="Sur les écrans" href="/cinema">Cinéma à Toulouse</x-ui.section-heading>
                <div class="mt-6 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($latestMovies as $movie)
                        <a
                            href="/cinema/films/{{ $movie->slug }}"
                            data-track="movie:{{ $movie->id }}:homepage_cinema"
                            class="group block overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                        >
                            <div class="aspect-[2/3] w-full overflow-hidden bg-ink-100">
                                @if ($movie->poster_url)
                                    <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" loading="lazy" class="h-full w-full object-cover transition group-hover:scale-105">
                                @endif
                            </div>
                            <p class="p-2 text-sm font-medium text-ink-800 group-hover:text-brand-700">{{ $movie->title }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Annuaire (fiches payantes mises en avant) --}}
        @if ($featuredListings->isNotEmpty())
            <section aria-labelledby="annuaire-heading">
                <x-ui.section-heading eyebrow="Ils font vivre Toulouse" href="/annuaire">Annuaire des commerces &amp; services</x-ui.section-heading>
                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($featuredListings as $listing)
                        <x-ui.card
                            :href="'/annuaire/fiche/'.$listing->slug"
                            :image="$listing->getFirstMediaUrl('logo')"
                            :eyebrow="$listing->categories->first()?->name"
                            :title="$listing->title"
                            :meta="$listing->city"
                            :track="'listing:'.$listing->id.':homepage_annuaire'"
                        >
                            <x-ui.badge color="accent">Partenaire</x-ui.badge>
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Annonces --}}
        @if ($latestClassifieds->isNotEmpty())
            <section aria-labelledby="annonces-heading">
                <x-ui.section-heading eyebrow="Dernières annonces" href="/annonces">Petites annonces</x-ui.section-heading>
                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($latestClassifieds as $classified)
                        <x-ui.card
                            :href="'/annonces/'.$classified->slug"
                            :eyebrow="$classified->category?->name"
                            :title="$classified->title"
                            :meta="$classified->price ? number_format($classified->price, 0, ',', ' ').' €' : null"
                            :track="'classified:'.$classified->id.':homepage_annonces'"
                        />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Newsletter (demande client, 12/09/2026, voir TECHNICAL_DOCUMENTATION.md §36) --}}
        <section class="rounded-3xl border border-brand-100 bg-brand-50 px-6 py-10 sm:px-12" aria-labelledby="newsletter-heading">
            <div class="mx-auto max-w-2xl text-center">
                <h2 id="newsletter-heading" class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">
                    Ne manquez rien de Toulouse
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-ink-600">
                    Actualités, agenda, bons plans : recevez le meilleur de ToulouseWeb directement par email.
                </p>

                @if (session('status'))
                    {{-- Le bandeau générique du layout affiche déjà ce message en haut de page ;
                    on le répète ici, au plus près du formulaire, pour qu'il reste visible même
                    si le visiteur a fait défiler la page avant de soumettre. --}}
                    <p class="mt-4 rounded-lg bg-green-100 px-4 py-2 text-sm font-medium text-green-800">{{ session('status') }}</p>
                @endif

                <form method="POST" action="{{ route('newsletter.subscribe') }}" class="mx-auto mt-6 flex max-w-md flex-col gap-3 sm:flex-row">
                    @csrf
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <label for="newsletter-website">Laisser vide</label>
                        <input type="text" name="website" id="newsletter-website" tabindex="-1" autocomplete="off">
                    </div>
                    <label for="newsletter-email" class="sr-only">Adresse email</label>
                    <input
                        type="email" name="email" id="newsletter-email" required placeholder="votre@email.fr"
                        class="w-full flex-1 rounded-full border border-ink-200 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none"
                    >
                    <button type="submit" class="inline-flex items-center justify-center rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                        S'inscrire
                    </button>
                </form>
                <x-ui.field-error name="email" />
                <p class="mt-3 text-xs text-ink-400">Désinscription possible à tout moment en un clic.</p>
            </div>
        </section>

        {{-- CTA dépôt d'annonce / proposer un événement --}}
        <section class="rounded-3xl bg-ink-900 px-6 py-10 text-center sm:px-12">
            <h2 class="font-heading text-2xl font-bold text-white sm:text-3xl">Une actu, un événement, une annonce ?</h2>
            <p class="mx-auto mt-3 max-w-xl text-ink-300">
                Proposez votre contenu à la communauté ToulouseWeb — chaque soumission est vérifiée par notre équipe avant publication.
            </p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <x-ui.button href="/annonces/deposer" variant="primary" size="lg">Déposer une annonce</x-ui.button>
                <x-ui.button href="/agenda/proposer" variant="outline" size="lg" class="!border-white/30 !text-white hover:!bg-white/10">
                    Proposer un événement
                </x-ui.button>
            </div>
        </section>
    </div>
</x-layouts.app>
