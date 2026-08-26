@props(['slides'])

@if ($slides->isEmpty())
    {{-- Rien d'administré pour l'instant : pas de bloc vide moche, on masque simplement --}}
@else
<section
    x-data="{
        active: 0,
        count: {{ $slides->count() }},
        timer: null,
        start() {
            this.stop();
            this.timer = setInterval(() => { this.active = (this.active + 1) % this.count }, {{ $slides->first()->delay_ms ?? 5000 }});
        },
        stop() { if (this.timer) clearInterval(this.timer) },
    }"
    x-init="start()"
    @mouseenter="stop()"
    @mouseleave="start()"
    class="relative overflow-hidden bg-ink-900"
    aria-roledescription="carrousel"
    aria-label="Mises en avant ToulouseWeb"
>
    <div class="relative h-[320px] sm:h-[420px] lg:h-[480px]">
        @foreach ($slides as $i => $slide)
            <a
                href="{{ $slide->link_url ?? '#' }}"
                data-track="slider:{{ $slide->id }}:homepage_hero"
                x-show="active === {{ $i }}"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                @if ($i !== 0) x-cloak @endif
                class="absolute inset-0 block"
                aria-label="{{ $slide->title }}"
            >
                <img
                    src="{{ $slide->image_url }}"
                    alt="{{ $slide->title }}"
                    loading="{{ $i === 0 ? 'eager' : 'lazy' }}"
                    fetchpriority="{{ $i === 0 ? 'high' : 'auto' }}"
                    class="h-full w-full object-cover opacity-80"
                >
                <div class="absolute inset-0 bg-gradient-to-t from-ink-900/80 via-ink-900/10 to-transparent"></div>
                <div class="absolute inset-x-0 bottom-0 p-6 sm:p-10">
                    <p class="font-heading text-xl font-bold text-white sm:text-3xl">{{ $slide->title }}</p>
                    @if ($slide->client_name)
                        <p class="mt-1 text-sm text-white/80">{{ $slide->client_name }}</p>
                    @endif
                </div>
            </a>
        @endforeach
    </div>

    @if ($slides->count() > 1)
        {{-- Flèches précédent/suivant (demande client) — chevrons #CC0000 sur
        fond blanc pour rester lisibles quelle que soit l'image du slide. --}}
        <button
            type="button"
            @click="active = (active - 1 + count) % count"
            class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-sm transition hover:bg-white sm:left-4 sm:h-12 sm:w-12"
            aria-label="Diapositive précédente"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#CC0000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 sm:h-6 sm:w-6" aria-hidden="true">
                <path d="M15.75 19.5L8.25 12l7.5-7.5" />
            </svg>
        </button>
        <button
            type="button"
            @click="active = (active + 1) % count"
            class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-sm transition hover:bg-white sm:right-4 sm:h-12 sm:w-12"
            aria-label="Diapositive suivante"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#CC0000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 sm:h-6 sm:w-6" aria-hidden="true">
                <path d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
        </button>

        <div class="absolute inset-x-0 bottom-4 flex justify-center gap-2">
            @foreach ($slides as $i => $slide)
                <button
                    type="button"
                    @click="active = {{ $i }}"
                    class="h-2 w-2 rounded-full transition"
                    :class="active === {{ $i }} ? 'bg-white w-6' : 'bg-white/50'"
                    aria-label="Aller à la diapositive {{ $i + 1 }}"
                ></button>
            @endforeach
        </div>
    @endif
</section>
@endif
