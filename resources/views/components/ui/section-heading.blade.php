@props(['eyebrow' => null, 'href' => null, 'linkLabel' => 'Tout voir'])

<div class="flex items-end justify-between gap-4">
    <div>
        @if ($eyebrow)
            <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">{{ $eyebrow }}</p>
        @endif
        <h2 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">{{ $slot }}</h2>
    </div>
    @if ($href)
        <a href="{{ $href }}" class="hidden shrink-0 items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800 sm:inline-flex">
            {{ $linkLabel }}
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 011.06 0l4.5 4.5a.75.75 0 010 1.06l-4.5 4.5a.75.75 0 11-1.06-1.06l3.22-3.22H3a.75.75 0 010-1.5h12.94l-3.22-3.22a.75.75 0 010-1.06z" clip-rule="evenodd" />
            </svg>
        </a>
    @endif
</div>
