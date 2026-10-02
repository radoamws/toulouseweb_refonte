@php
    // Schema.org Event (brief §13).
    //
    // ⚠️ Champs ajoutés/corrigés le 02/10/2026 suite à un rapport Google
    // Search Console sur des dizaines de fiches ("performer"/"organizer"
    // manquants sur ~63, "offers" manquant sur ~62, format de prix invalide) :
    // - `offers` était OMIS dès que `price` était vide (la majorité des
    //   événements) — désormais toujours présent (Google l'exige pour le
    //   rich result Event), avec repli sur la page de l'événement elle-même
    //   si aucun lien de billetterie n'est connu (`url` reste obligatoire).
    // - `price` utilise désormais `structured_data_price` (voir son docblock
    //   sur App\Models\Event) — `price` brut est un texte libre ("de 8 € à
    //   15 €"...), jamais un nombre, rejeté par la validation schema.org.
    // - `performer`/`organizer` : aucun champ dédié n'existe dans le modèle
    //   (pas d'artiste/compagnie distinct du titre côté scraping) — repli
    //   honnête sur le LIEU réel de l'événement (`venue_display_name`),
    //   qui organise/accueille bien la représentation, plutôt que d'inventer
    //   une donnée qu'on n'a pas.
    // - `image` replie sur l'image de marque ToulouseWeb (déjà utilisée
    //   comme repli visuel sur tout le site, voir §64) plutôt que de
    //   laisser le champ absent.
    $venueOrSite = $event->venue_display_name ?: config('app.name', 'ToulouseWeb');

    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $event->title,
        'description' => $event->description ? strip_tags($event->description) : null,
        'startDate' => $event->start_date->toIso8601String(),
        'endDate' => $event->end_date?->toIso8601String(),
        'eventStatus' => $event->status === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
        'image' => $event->image_url ?: asset('branding/default-card-image.png'),
        'offers' => [
            '@type' => 'Offer',
            'url' => $event->booking_url ?: $event->publicUrl(),
            'price' => $event->structured_data_price,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'validFrom' => $event->created_at->toIso8601String(),
        ],
        'performer' => ['@type' => 'Organization', 'name' => $venueOrSite],
        'organizer' => array_filter([
            '@type' => 'Organization',
            'name' => $venueOrSite,
            'url' => $event->booking_url ?: url('/'),
        ]),
        // Lieu RÉEL de l'événement, pas l'Area générique (demande client,
        // 23/09/2026) — voir Event::venueDisplayName()/venueDisplayAddress().
        'location' => $event->venue_display_name ? array_filter([
            '@type' => 'Place',
            'name' => $event->venue_display_name,
            'address' => $event->venue_display_address,
        ]) : null,
    ]);
@endphp

<x-layouts.app :seo="$seo">
    @push('head')
        <script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>
    @endpush

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[
            ['label' => 'Agenda', 'href' => '/agenda'],
            ...($event->categories->first() ? [['label' => $event->categories->first()->name, 'href' => '/agenda/'.$event->categories->first()->slug]] : []),
            ['label' => $event->title],
        ]" />

        <div class="mb-6 aspect-video w-full overflow-hidden rounded-2xl">
            <x-ui.event-thumbnail :event="$event" class="h-full w-full object-cover" />
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($event->categories as $cat)
                <x-ui.badge>{{ $cat->name }}</x-ui.badge>
            @endforeach
            @if ($event->status === 'expired')
                <x-ui.badge color="ink">Événement passé</x-ui.badge>
            @elseif ($event->status === 'cancelled')
                <x-ui.badge color="danger">Annulé</x-ui.badge>
            @endif
        </div>

        <h1 class="mt-3 font-heading text-3xl font-bold text-ink-900">{{ $event->title }}</h1>
        @if ($event->subtitle)
            <p class="mt-2 text-lg text-ink-600">{{ $event->subtitle }}</p>
        @endif

        <dl class="mt-6 grid gap-4 rounded-2xl border border-ink-100 bg-white p-6 shadow-sm sm:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-ink-500">Date</dt>
                <dd class="text-ink-800">
                    {{ $event->start_date->translatedFormat('d F Y') }}
                    @if ($event->end_date && ! $event->end_date->isSameDay($event->start_date))
                        &rarr; {{ $event->end_date->translatedFormat('d F Y') }}
                    @endif
                </dd>
            </div>
            {{-- Nom/adresse RÉELS de l'événement en priorité, pas ceux
            (génériques) de l'Area — demande client, 23/09/2026 : "l'adresse
            de l'événement n'est pas l'adresse du 'Lieu'" (agendas
            mutualisés type OpenAgenda, un même Area agrège des événements à
            des adresses différentes). Voir Event::venueDisplayName(). --}}
            @if ($event->venue_display_name)
                <div>
                    <dt class="text-sm font-medium text-ink-500">Lieu</dt>
                    <dd class="text-ink-800">{{ $event->venue_display_name }}@if($event->venue_display_address) — {{ $event->venue_display_address }}@endif</dd>
                </div>
            @endif
            @if ($event->price)
                <div>
                    <dt class="text-sm font-medium text-ink-500">Tarif</dt>
                    <dd class="text-ink-800">{{ $event->price }}</dd>
                </div>
            @endif
            @if (! empty($event->schedule))
                <div>
                    <dt class="text-sm font-medium text-ink-500">Horaires</dt>
                    <dd class="text-ink-800">
                        @foreach ($event->schedule as $entry)
                            <span class="block">{{ $entry }}</span>
                        @endforeach
                    </dd>
                </div>
            @endif
        </dl>

        @if ($event->description)
            <div class="prose prose-ink mt-8 max-w-none">{!! nl2br(e($event->description)) !!}</div>
        @endif

        @if ($event->booking_url)
            <x-ui.button :href="$event->booking_url" target="_blank" rel="noopener" variant="primary" size="lg" class="mt-8" data-track="event:{{ $event->id }}:booking_click">
                Réserver / en savoir plus
            </x-ui.button>
        @endif

        @if ($related->isNotEmpty())
            <div class="mt-12">
                <h2 class="font-heading text-lg font-semibold text-ink-900">À voir aussi</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-ui.card
                            :href="'/agenda/'.$item->slug"
                            :image="$item->image_url"
                            :title="$item->title"
                            :meta="$item->start_date->translatedFormat('d M Y')"
                            :track="'event:'.$item->id.':agenda_related'"
                        />
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
