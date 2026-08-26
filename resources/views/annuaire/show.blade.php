@php
    // Schema.org LocalBusiness/Restaurant (brief §13) — uniquement pour les
    // fiches payantes, qui seules portent une page détaillée indexable (§5).
    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => $listing->isRestaurant() ? 'Restaurant' : 'LocalBusiness',
        'name' => $listing->title,
        'image' => $listing->getFirstMediaUrl('logo') ?: null,
        'description' => $listing->short_description,
        'telephone' => $listing->phone,
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
                    <div class="mt-6">
                        <h2 class="font-heading text-lg font-semibold text-ink-900">Photos</h2>
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($listing->getMedia('gallery') as $photo)
                                <a href="{{ $photo->getUrl() }}" target="_blank" rel="noopener" class="aspect-square overflow-hidden rounded-xl bg-ink-100">
                                    <img src="{{ $photo->getUrl() }}" alt="{{ $listing->title }}" loading="lazy" class="h-full w-full object-cover transition hover:scale-105">
                                </a>
                            @endforeach
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
                    @if ($listing->phone)
                        <div>
                            <dt class="font-medium text-ink-500">Téléphone</dt>
                            <dd><a href="tel:{{ $listing->phone }}" class="text-brand-700 hover:underline" data-track="listing:{{ $listing->id }}:phone_click">{{ $listing->phone }}</a></dd>
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
