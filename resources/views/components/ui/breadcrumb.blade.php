@props(['items']) {{-- [['label' => 'Annuaire', 'href' => '/annuaire'], ['label' => 'Fiche']] --}}

@php
    // Données structurées BreadcrumbList (brief §13) en plus du fil visuel.
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($items)->values()->map(fn ($item, $i) => array_filter([
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $item['label'],
            'item' => isset($item['href']) ? url($item['href']) : null,
        ]))->all(),
    ];
@endphp

<script type="application/ld+json">{!! json_encode($jsonLd) !!}</script>

<nav aria-label="Fil d'Ariane" class="mb-6 text-sm text-ink-500">
    <ol class="flex flex-wrap items-center gap-1">
        <li><a href="/" class="hover:text-brand-700">Accueil</a></li>
        @foreach ($items as $item)
            <li class="flex items-center gap-1">
                <span aria-hidden="true">/</span>
                @if (!empty($item['href']) && !$loop->last)
                    <a href="{{ $item['href'] }}" class="hover:text-brand-700">{{ $item['label'] }}</a>
                @else
                    <span class="font-medium text-ink-700" aria-current="page">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
