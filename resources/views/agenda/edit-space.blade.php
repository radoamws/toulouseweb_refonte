<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Agenda', 'href' => '/agenda'], ['label' => 'Mon espace']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Gérer mon événement</h1>
        <p class="mt-2 text-ink-600">« {{ $event->title }} »</p>

        @if ($event->pending_changes)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Une modification est déjà en attente de validation par notre équipe. Vous pouvez la corriger ci-dessous, elle remplacera la précédente.
            </div>
        @endif

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @php($current = $event->pending_changes ?? $event->only(['title', 'description', 'price', 'booking_url']) + ['start_date' => optional($event->start_date)->format('Y-m-d\TH:i'), 'end_date' => optional($event->end_date)->format('Y-m-d\TH:i')])

        <form method="POST" action="{{ route('agenda.updateEditSpace', $event->edit_token) }}" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Titre de l'événement *</label>
                <input type="text" name="title" id="title" required value="{{ old('title', $current['title']) }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-ink-700">Date de début *</label>
                    <input type="datetime-local" name="start_date" id="start_date" required value="{{ old('start_date', $current['start_date']) }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="start_date" />
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-ink-700">Date de fin</label>
                    <input type="datetime-local" name="end_date" id="end_date" value="{{ old('end_date', $current['end_date']) }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="end_date" />
                </div>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-ink-700">Description</label>
                <textarea name="description" id="description" rows="5"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('description', $current['description'] ?? '') }}</textarea>
                <x-ui.field-error name="description" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="price" class="block text-sm font-medium text-ink-700">Tarif</label>
                    <input type="text" name="price" id="price" value="{{ old('price', $current['price'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="price" />
                </div>
                <div>
                    <label for="booking_url" class="block text-sm font-medium text-ink-700">Lien de réservation</label>
                    <input type="url" name="booking_url" id="booking_url" value="{{ old('booking_url', $current['booking_url'] ?? '') }}" placeholder="https://"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="booking_url" />
                </div>
            </div>

            <p class="text-xs text-ink-500">La catégorie, le lieu et la photo ne sont pas modifiables depuis cet espace pour le moment — contactez-nous si besoin.</p>

            <x-ui.button type="submit" variant="primary" size="lg">Enregistrer ma modification</x-ui.button>
        </form>
    </div>
</x-layouts.app>
