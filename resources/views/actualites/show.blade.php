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
@endphp

<x-layouts.app :seo="$seo">
    @push('head')
        <script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>
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

        <div class="prose prose-ink mt-6 max-w-none">{!! $news->body !!}</div>

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
