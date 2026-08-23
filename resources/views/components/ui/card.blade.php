@props([
    'href' => '#',
    'image' => null,
    'eyebrow' => null,
    'title' => null,
    'meta' => null,
    'track' => null, // ex: "listing:12:homepage_annuaire"
])

<a
    href="{{ $href }}"
    @if ($track) data-track="{{ $track }}" @endif
    class="group flex flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
>
    <div class="aspect-[4/3] w-full overflow-hidden bg-ink-100">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $title }}" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
        @else
            <div class="flex h-full w-full items-center justify-center text-ink-300">
                <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 4.5h18M3 19.5h18M4.5 4.5v15m15-15v15" />
                </svg>
            </div>
        @endif
    </div>
    <div class="flex flex-1 flex-col gap-2 p-4">
        @if ($eyebrow)
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-600">{{ $eyebrow }}</p>
        @endif
        <h3 class="font-heading text-base font-semibold leading-snug text-ink-900 group-hover:text-brand-700">
            {{ $title }}
        </h3>
        @if ($meta)
            <p class="mt-auto text-sm text-ink-500">{{ $meta }}</p>
        @endif
        {{ $slot }}
    </div>
</a>
