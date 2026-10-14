<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Transports']]" />

        <h1 class="font-heading text-2xl font-bold text-ink-900 sm:text-3xl">Transports en commun à Toulouse</h1>
        <p class="mt-2 text-ink-600">
            Métro, tramway, téléphérique Téléo et bus Tisséo — premier/dernier départ, fréquence indicative et arrêts desservis.
            Horaires théoriques, issus de l'open data Tisséo ; pour le temps réel, direction
            <a href="https://www.tisseo.fr" target="_blank" rel="noopener" class="text-brand-700 underline decoration-dotted underline-offset-2">tisseo.fr</a>.
        </p>

        @if ($lines->isEmpty())
            <p class="mt-10 text-ink-500">Aucune ligne disponible pour le moment.</p>
        @else
            <div class="mt-8 space-y-10">
                @foreach ($lines as $type => $typeLines)
                    <section aria-label="{{ \App\Models\TransportLine::TYPE_LABELS[$type] ?? $type }}">
                        <h2 class="font-heading text-lg font-semibold text-ink-900">{{ \App\Models\TransportLine::TYPE_LABELS[$type] ?? ucfirst($type) }}</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($typeLines as $line)
                                <a
                                    href="/transports/{{ $line->slug }}"
                                    data-track="transport_line:{{ $line->id }}:transports_listing"
                                    class="inline-flex items-center gap-2 rounded-full border border-ink-100 bg-white px-3 py-1.5 text-sm font-medium text-ink-800 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <span class="h-3 w-3 rounded-full" style="background-color: #{{ $line->color ?? 'cc0000' }};" aria-hidden="true"></span>
                                    {{ $line->name }}
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
