@props([
    'variant' => 'primary', // primary | secondary | outline | ghost
    'size' => 'md', // sm | md | lg
    'href' => null,
    'tag' => null,
])

@php
    $tag ??= $href ? 'a' : 'button';

    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600',
        'secondary' => 'bg-ink-800 text-white hover:bg-ink-900 focus-visible:outline-ink-800',
        'outline' => 'border border-ink-200 text-ink-800 hover:bg-ink-50 focus-visible:outline-ink-400',
        'ghost' => 'text-brand-700 hover:bg-brand-50 focus-visible:outline-brand-600',
    ];
    $sizes = [
        'sm' => 'px-3 py-1.5 text-sm',
        'md' => 'px-4 py-2.5 text-sm',
        'lg' => 'px-6 py-3 text-base',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold shadow-sm transition '
        .'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

<{{ $tag }} {{ $attributes->merge(['class' => $classes]) }} @if ($tag === 'a') href="{{ $href }}" @endif>
    {{ $slot }}
</{{ $tag }}>
