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
        <x-ui.entity-image :src="$image" :alt="$title" class="transition duration-300 group-hover:scale-105" />
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
