@php
    // Schema.org LocalBusiness/Restaurant (brief §13) — uniquement pour les
    // fiches payantes, qui seules portent une page détaillée indexable (§5).
    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => $listing->isRestaurant() ? 'Restaurant' : 'LocalBusiness',
        'name' => $listing->title,
        'image' => $listing->getFirstMediaUrl('logo') ?: null,
        'description' => $listing->short_description,
        // `clean_phone`, pas `phone` : voir docblock de Listing::cleanPhone()
        // (bug réel corrigé le 08/09/2026, audit SEO final) — 5,5% des
        // fiches ont un `phone` legacy mélangeant téléphone/email/HTML brut.
        'telephone' => $listing->clean_phone,
        'email' => $listing->email,
        'url' => $listing->website,
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $listing->address,
            'addressLocality' => $listing->city,
            'postalCode' => $listing->postal_code,
            'addressCountry' => 'FR',
        ]),
        'geo' => $listing->lat && $listing->lng ? [
            '@type' => 'GeoCoordinates',
            'latitude' => $listing->lat,
            'longitude' => $listing->lng,
        ] : null,
    ]);
@endphp

<x-layouts.app :seo="$seo">
    @push('head')
        <script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>
    @endpush

    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[
            ['label' => 'Annuaire', 'href' => '/annuaire'],
            ...($listing->categories->first() ? [['label' => $listing->categories->first()->name, 'href' => '/annuaire/'.$listing->categories->first()->slug]] : []),
            ['label' => $listing->title],
        ]" />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($listing->categories as $cat)
                        <x-ui.badge>{{ $cat->name }}</x-ui.badge>
                    @endforeach
                    @if ($listing->isPaid())
                        <x-ui.badge color="accent">Partenaire</x-ui.badge>
                    @endif
                </div>
                <h1 class="mt-3 font-heading text-3xl font-bold text-ink-900">{{ $listing->title }}</h1>
                @if ($listing->short_description)
                    <p class="mt-2 text-lg text-ink-600">{{ $listing->short_description }}</p>
                @endif
            </div>
        </div>

        @if ($listing->getFirstMediaUrl('logo'))
            <div class="mt-6 aspect-[16/9] w-full overflow-hidden rounded-2xl bg-ink-100 sm:aspect-[21/9]">
                <img src="{{ $listing->getFirstMediaUrl('logo') }}" alt="{{ $listing->title }}" class="h-full w-full object-cover">
            </div>
        @endif

        <div class="mt-8 grid gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                @if ($listing->isPaid() && $listing->description)
                    <div class="prose prose-ink max-w-none">{!! nl2br(e($listing->description)) !!}</div>
                @endif

                @if ($listing->getMedia('gallery')->isNotEmpty())
                    {{-- Aperçu grand format en overlay avec navigation chevron
                    gauche/droite (demande client) — remplace l'ouverture en
                    nouvel onglet. `photos` : URLs en JSON pour Alpine.js
                    (@js échappe correctement pour un attribut HTML). --}}
                    <div
                        x-data="{ open: false, index: 0, photos: @js($listing->getMedia('gallery')->map(fn ($m) => $m->getUrl())->values()) }"
                        @keydown.escape.window="open = false"
                        @keydown.arrow-left.window="if (open) index = (index - 1 + photos.length) % photos.length"
                        @keydown.arrow-right.window="if (open) index = (index + 1) % photos.length"
                        class="mt-6"
                    >
                        <h2 class="font-heading text-lg font-semibold text-ink-900">Photos</h2>
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($listing->getMedia('gallery') as $i => $photo)
                                <button
                                    type="button"
                                    @click="open = true; index = {{ $i }}"
                                    class="aspect-square overflow-hidden rounded-xl bg-ink-100"
                                    aria-label="Agrandir la photo {{ $i + 1 }}"
                                >
                                    <img src="{{ $photo->getUrl() }}" alt="{{ $listing->title }}" loading="lazy" class="h-full w-full object-cover transition hover:scale-105">
                                </button>
                            @endforeach
                        </div>

                        {{-- Overlay plein écran --}}
                        <div
                            x-show="open"
                            x-cloak
                            x-transition.opacity
                            @click="open = false"
                            class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/95 p-4"
                            role="dialog"
                            aria-modal="true"
                            aria-label="Photo en grand format"
                        >
                            <button
                                type="button"
                                @click="open = false"
                                class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                                aria-label="Fermer"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6" aria-hidden="true">
                                    <path d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>

                            <button
                                type="button"
                                @click.stop="index = (index - 1 + photos.length) % photos.length"
                                x-show="photos.length > 1"
                                class="absolute left-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:left-6"
                                aria-label="Photo précédente"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6" aria-hidden="true">
                                    <path d="M15.75 19.5L8.25 12l7.5-7.5" />
                                </svg>
                            </button>

                            <img
                                :src="photos[index]"
                                @click.stop
                                alt="{{ $listing->title }}"
                                class="max-h-[85vh] max-w-[90vw] rounded-lg object-contain shadow-2xl"
                            >

                            <button
                                type="button"
                                @click.stop="index = (index + 1) % photos.length"
                                x-show="photos.length > 1"
                                class="absolute right-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:right-6"
                                aria-label="Photo suivante"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6" aria-hidden="true">
                                    <path d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>

                            <p x-show="photos.length > 1" class="absolute bottom-4 left-1/2 -translate-x-1/2 text-sm text-white/70" x-text="(index + 1) + ' / ' + photos.length"></p>
                        </div>
                    </div>
                @endif

                @if ($listing->amenities->isNotEmpty())
                    <div class="mt-6">
                        <h2 class="font-heading text-lg font-semibold text-ink-900">Équipements &amp; services</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($listing->amenities as $amenity)
                                <x-ui.badge color="ink">{{ $amenity->name }}</x-ui.badge>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($related->isNotEmpty())
                    <div class="mt-10">
                        <h2 class="font-heading text-lg font-semibold text-ink-900">Dans la même catégorie</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            @foreach ($related as $item)
                                <x-ui.card :href="'/annuaire/fiche/'.$item->slug" :image="$item->getFirstMediaUrl('logo')" :title="$item->title" :meta="$item->city" :track="'listing:'.$item->id.':annuaire_related'" />
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Coordonnées : toujours visibles, même pour une fiche gratuite (brief §5) --}}
            <aside class="rounded-2xl border border-ink-100 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-ink-900">Coordonnées</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    @if ($listing->address)
                        <div>
                            <dt class="font-medium text-ink-500">Adresse</dt>
                            <dd class="text-ink-800">{{ $listing->address }}@if($listing->city), {{ $listing->city }}@endif</dd>
                        </div>
                    @endif
                    {{-- `clean_phone`, pas `phone` : voir docblock de Listing::cleanPhone()
                    (bug réel corrigé le 08/09/2026, audit SEO final) — le `phone` legacy brut
                    mélangeait parfois téléphone/email/HTML ("Tel: ...<br>Mail: ..."), rendu
                    tel quel affichait littéralement "<br>" sur la page et cassait le lien tel:. --}}
                    @if ($listing->clean_phone)
                        <div>
                            <dt class="font-medium text-ink-500">Téléphone</dt>
                            <dd><a href="tel:{{ $listing->clean_phone }}" class="text-brand-700 hover:underline" data-track="listing:{{ $listing->id }}:phone_click">{{ $listing->clean_phone }}</a></dd>
                        </div>
                    @endif
                    @if ($listing->isPaid())
                        @if ($listing->email)
                            <div>
                                <dt class="font-medium text-ink-500">Email</dt>
                                <dd><a href="mailto:{{ $listing->email }}" class="text-brand-700 hover:underline">{{ $listing->email }}</a></dd>
                            </div>
                        @endif
                        @if ($listing->website)
                            <div>
                                <dt class="font-medium text-ink-500">Site web</dt>
                                <dd><a href="{{ $listing->website }}" target="_blank" rel="noopener" class="text-brand-700 hover:underline" data-track="listing:{{ $listing->id }}:website_click">Visiter le site</a></dd>
                            </div>
                        @endif
                        @if ($listing->reservation_url)
                            <x-ui.button :href="$listing->reservation_url" target="_blank" rel="noopener" variant="primary" class="w-full !justify-center" data-track="listing:{{ $listing->id }}:reservation_click">
                                Réserver
                            </x-ui.button>
                        @endif
                    @endif
                </dl>
            </aside>
        </div>
    </div>
</x-layouts.app>
