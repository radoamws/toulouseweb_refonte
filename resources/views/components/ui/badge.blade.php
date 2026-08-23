@props(['color' => 'brand'])

@php
    $colors = [
        'brand' => 'bg-brand-50 text-brand-700',
        'accent' => 'bg-accent-400/20 text-accent-600',
        'success' => 'bg-green-50 text-green-700',
        'warning' => 'bg-amber-50 text-amber-700',
        'danger' => 'bg-red-50 text-red-700',
        'ink' => 'bg-ink-100 text-ink-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold '.($colors[$color] ?? $colors['brand'])]) }}>
    {{ $slot }}
</span>
