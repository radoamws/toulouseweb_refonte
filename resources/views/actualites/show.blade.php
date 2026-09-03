@php
    // Schema.org NewsArticle (brief §13).
    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $news->title,
        'description' => $news->excerpt,
        'image' => $news->image_url,
        'datePublished' => $news->published_at?->toIso8601String(),
        'dateModified' => $news->updated_at->toIso8601String(),
        'author' => $news->author ? ['@type' => 'Person', 'name' => $news->author->name] : ['@type' => 'Organization', 'name' => 'ToulouseWeb'],
    ]);

    // Événement décrit par l'article (demande client, 03/09/2026) — schema.org
    // Event distinct de NewsArticle, seulement si l'article porte une date de
    // début (les champs suivants n'ont de sens que pour un article-événement).
    $eventJsonLd = $news->start_date ? array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $news->title,
        'startDate' => $news->start_date->toDateString(),
        'endDate' => $news->end_date?->toDateString(),
        'location' => $news->address ? ['@type' => 'Place', 'name' => $news->address] : null,
        'offers' => $news->price ? ['@type' => 'Offer', 'price' => $news->price, 'priceCurrency' => 'EUR'] : null,
    ]) : null;
@endphp

<x-layouts.app :seo="$seo">
    @push('head')
        <script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>
        @if ($eventJsonLd)
            <script type="application/ld+json">{!! json_encode($eventJsonLd) !!}</script>
        @endif
    @endpush

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[
            ['label' => 'Actualités', 'href' => '/actualites'],
            ...($news->category ? [['label' => $news->category->name, 'href' => '/actualites/'.$news->category->slug]] : []),
            ['label' => $news->title],
        ]" />

        @if ($news->image_url)
            <img src="{{ $news->image_url }}" alt="{{ $news->title }}" class="mb-6 aspect-video w-full rounded-2xl object-cover">
        @endif

        @if ($news->category)
            <x-ui.badge>{{ $news->category->name }}</x-ui.badge>
        @endif
        <h1 class="mt-3 font-heading text-3xl font-bold text-ink-900">{{ $news->title }}</h1>
        <p class="mt-2 text-sm text-ink-500">{{ $news->published_at?->translatedFormat('d F Y') }}</p>

        {{-- Informations pratiques de l'événement décrit par l'article (demande
        client, 03/09/2026) — chaque ligne n'apparaît que si elle a une valeur. --}}
        @if ($news->start_date || $news->schedule || $news->address || $news->price || $news->phone || $news->email || $news->website)
            <dl class="mt-6 grid gap-4 rounded-2xl border border-ink-100 bg-white p-6 shadow-sm sm:grid-cols-2">
                @if ($news->start_date)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Date</dt>
                        <dd class="text-ink-800">
                            {{ $news->start_date->translatedFormat('d F Y') }}
                            @if ($news->end_date && ! $news->end_date->isSameDay($news->start_date))
                                &rarr; {{ $news->end_date->translatedFormat('d F Y') }}
                            @endif
                        </dd>
                    </div>
                @endif
                @if ($news->schedule)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Horaire</dt>
                        <dd class="text-ink-800">{{ $news->schedule }}</dd>
                    </div>
                @endif
                @if ($news->address)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Lieu</dt>
                        <dd class="text-ink-800">{{ $news->address }}</dd>
                    </div>
                @endif
                @if ($news->price)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Tarif</dt>
                        <dd class="text-ink-800">{{ $news->price }}</dd>
                    </div>
                @endif
                @if ($news->phone)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Téléphone</dt>
                        <dd><a href="tel:{{ $news->phone }}" class="text-brand-700 hover:underline" data-track="news:{{ $news->id }}:phone_click">{{ $news->phone }}</a></dd>
                    </div>
                @endif
                @if ($news->email)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Email</dt>
                        <dd><a href="mailto:{{ $news->email }}" class="text-brand-700 hover:underline">{{ $news->email }}</a></dd>
                    </div>
                @endif
                @if ($news->website)
                    <div>
                        <dt class="text-sm font-medium text-ink-500">Site web</dt>
                        <dd><a href="{{ $news->website }}" target="_blank" rel="noopener" class="text-brand-700 hover:underline" data-track="news:{{ $news->id }}:website_click">Visiter le site</a></dd>
                    </div>
                @endif
            </dl>
        @endif

        <div class="prose prose-ink mt-6 max-w-none">{!! $news->body !!}</div>

        @if ($news->youtube_embed_url)
            <div class="mt-8">
                <h2 class="font-heading text-lg font-semibold text-ink-900">Vidéo</h2>
                <div class="mt-3 aspect-video w-full overflow-hidden rounded-2xl bg-ink-900 shadow-sm">
                    <iframe
                        src="{{ $news->youtube_embed_url }}"
                        title="Vidéo — {{ $news->title }}"
                        class="h-full w-full"
                        loading="lazy"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        allowfullscreen
                    ></iframe>
                </div>
            </div>
        @endif

        @if ($related->isNotEmpty())
            <div class="mt-12">
                <h2 class="font-heading text-lg font-semibold text-ink-900">À lire aussi</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    @foreach ($related as $item)
                        <x-ui.card :href="'/actualites/'.$item->slug" :image="$item->image_url" :title="$item->title" :track="'news:'.$item->id.':actualites_related'" />
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
