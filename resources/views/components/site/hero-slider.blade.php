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
                    src="{{ $slide->image }}"
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
