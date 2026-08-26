@php
    $siteSettings = \App\Models\SiteSetting::current();
    // Structure de navigation cible (remplace le menu codé en dur dans
    // Header.vue du legacy, voir TECHNICAL_DOCUMENTATION.md §6). Les entrées
    // Restaurants/Enfants/Sports/Mariages/Spectacles/Sorties sont des
    // catégories de l'annuaire, Théâtre une catégorie de l'agenda (brief §6) —
    // regroupées ici en menus déroulants plutôt qu'en entrées de premier niveau.
    $primaryNav = [
        ['label' => 'Actualités', 'href' => '/actualites'],
        ['label' => 'Agenda', 'href' => '/agenda'],
        ['label' => 'Théâtre', 'href' => '/agenda/theatre'],
        ['label' => 'Cinéma', 'href' => '/cinema'],
        ['label' => 'Annonces', 'href' => '/annonces'],
        ['label' => 'Contact', 'href' => '/contact'],
    ];
    $annuaireNav = [
        ['label' => 'Tout l\'annuaire', 'href' => '/annuaire'],
        ['label' => 'Restaurants', 'href' => '/annuaire/restaurants'],
        ['label' => 'Enfants', 'href' => '/annuaire/enfants'],
        ['label' => 'Sports', 'href' => '/annuaire/sports'],
        ['label' => 'Mariages', 'href' => '/annuaire/mariages'],
        ['label' => 'Spectacles', 'href' => '/annuaire/spectacles'],
        ['label' => 'Sorties & Loisirs', 'href' => '/annuaire/sorties'],
    ];
@endphp
<header x-data="{ mobileOpen: false, annuaireOpen: false }" class="sticky top-0 z-40 border-b border-ink-100 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <a href="{{ url('/') }}" class="flex shrink-0 items-stretch self-stretch gap-2 font-heading text-xl font-bold text-brand-600">
            @if ($siteSettings->logo_url)
                {{-- Logo réel ToulouseWeb : texte blanc sur fond transparent
                (confirmé identique octet pour octet à la prod, voir
                TECHNICAL_DOCUMENTATION.md §13) — invisible sur l'entête clair
                de la refonte sans ce fond, le nom du site n'est donc pas
                répété à côté (déjà présent dans le logo lui-même). Encart
                sur toute la hauteur de l'entête (demande client) en rouge
                #CC0000 (couleur de marque, PAS un fond sombre neutre). --}}
                <span class="flex h-full items-center rounded-lg bg-[#CC0000] px-4">
                    <img src="{{ $siteSettings->logo_url }}" alt="{{ $siteSettings->site_name }}" class="h-11 w-auto">
                </span>
            @else
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-accent-500" aria-hidden="true"></span>
                {{ $siteSettings->site_name }}
            @endif
        </a>

        <nav class="hidden items-center gap-1 lg:flex" aria-label="Navigation principale">
            <div class="relative" @mouseleave="annuaireOpen = false">
                <button
                    type="button"
                    @click="annuaireOpen = !annuaireOpen"
                    @mouseenter="annuaireOpen = true"
                    class="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-ink-700 hover:bg-brand-50 hover:text-brand-700"
                    :aria-expanded="annuaireOpen.toString()"
                >
                    Annuaire
                    <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': annuaireOpen }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div
                    x-show="annuaireOpen"
                    x-transition
                    x-cloak
                    class="absolute left-0 mt-1 w-56 rounded-xl border border-ink-100 bg-white p-2 shadow-lg"
                >
                    @foreach ($annuaireNav as $item)
                        <a href="{{ $item['href'] }}" class="block rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-brand-50 hover:text-brand-700">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            @foreach ($primaryNav as $item)
                <a href="{{ $item['href'] }}" class="rounded-lg px-3 py-2 text-sm font-medium text-ink-700 hover:bg-brand-50 hover:text-brand-700">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="hidden shrink-0 lg:block">
            <a href="/annonces/deposer" class="inline-flex items-center rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                Déposer une annonce
            </a>
        </div>

        <button
            type="button"
            @click="mobileOpen = !mobileOpen"
            class="inline-flex items-center justify-center rounded-lg p-2 text-ink-700 hover:bg-ink-50 lg:hidden"
            :aria-expanded="mobileOpen.toString()"
            aria-label="Ouvrir le menu"
        >
            <svg x-show="!mobileOpen" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
            </svg>
            <svg x-show="mobileOpen" x-cloak class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav x-show="mobileOpen" x-cloak x-transition class="border-t border-ink-100 px-4 pb-4 lg:hidden" aria-label="Navigation mobile">
        <p class="mt-3 px-3 text-xs font-semibold uppercase tracking-wide text-ink-400">Annuaire</p>
        @foreach ($annuaireNav as $item)
            <a href="{{ $item['href'] }}" class="block rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-brand-50">{{ $item['label'] }}</a>
        @endforeach
        <div class="my-2 border-t border-ink-100"></div>
        @foreach ($primaryNav as $item)
            <a href="{{ $item['href'] }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-ink-700 hover:bg-brand-50">{{ $item['label'] }}</a>
        @endforeach
        <a href="/annonces/deposer" class="mt-3 block rounded-full bg-brand-600 px-4 py-2 text-center text-sm font-semibold text-white">
            Déposer une annonce
        </a>
    </nav>
</header>
