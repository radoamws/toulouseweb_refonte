@php
    // Schema.org Event (brief §13).
    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $event->title,
        'description' => $event->description ? strip_tags($event->description) : null,
        'startDate' => $event->start_date->toIso8601String(),
        'endDate' => $event->end_date?->toIso8601String(),
        'eventStatus' => $event->status === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
        'image' => $event->image_url,
        'offers' => $event->price ? ['@type' => 'Offer', 'price' => $event->price, 'priceCurrency' => 'EUR', 'url' => $event->booking_url] : null,
        'location' => $event->area ? array_filter([
            '@type' => 'Place',
            'name' => $event->area->name,
            'address' => $event->area->address,
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

        @if ($event->image_url)
            <img src="{{ $event->image_url }}" alt="{{ $event->title }}" class="mb-6 aspect-video w-full rounded-2xl object-cover">
        @endif

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
            @if ($event->area)
                <div>
                    <dt class="text-sm font-medium text-ink-500">Lieu</dt>
                    <dd class="text-ink-800">{{ $event->area->name }}@if($event->area->address) — {{ $event->area->address }}@endif</dd>
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
