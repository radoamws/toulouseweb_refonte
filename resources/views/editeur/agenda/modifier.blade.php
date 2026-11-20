@php($current = $event->pending_changes ?? $event->only(['title', 'venue_name', 'venue_address', 'description', 'price', 'booking_url']) + ['event_category_id' => $event->categories->first()?->id, 'start_date' => optional($event->start_date)->format('Y-m-d\TH:i'), 'end_date' => optional($event->end_date)->format('Y-m-d\TH:i')])
<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Mes publications', 'href' => route('editeur.mes-publications')], ['label' => 'Modifier mon événement']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Modifier mon événement</h1>
        <p class="mt-2 text-ink-600">« {{ $event->title }} »</p>

        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Votre modification sera revue par notre équipe avant d'être visible en ligne — l'événement publié reste inchangé pendant ce temps.
        </div>

        @if ($event->pending_changes)
            <div class="mt-4 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
                Une modification est déjà en attente de validation. Vous pouvez la corriger ci-dessous, elle remplacera la précédente.
            </div>
        @endif

        <form method="POST" action="{{ route('editeur.agenda.modifier.store', $event) }}" enctype="multipart/form-data" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="event_category_id" class="block text-sm font-medium text-ink-700">Catégorie *</label>
                <select name="event_category_id" id="event_category_id" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('event_category_id', $current['event_category_id']) == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="event_category_id" />
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Titre de l'événement *</label>
                <input type="text" name="title" id="title" required value="{{ old('title', $current['title']) }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="venue_name" class="block text-sm font-medium text-ink-700">Lieu *</label>
                    <input type="text" name="venue_name" id="venue_name" required value="{{ old('venue_name', $current['venue_name'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="venue_name" />
                </div>
                <div>
                    <label for="venue_address" class="block text-sm font-medium text-ink-700">Adresse du lieu</label>
                    <input type="text" name="venue_address" id="venue_address" value="{{ old('venue_address', $current['venue_address'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="venue_address" />
                </div>
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

            <div>
                <label for="image" class="block text-sm font-medium text-ink-700">Nouvelle photo (facultatif)</label>
                <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <p class="mt-1 text-xs text-ink-500">JPEG, PNG ou WEBP, 4 Mo maximum. Remplace la photo actuelle une fois la modification validée.</p>
                <x-ui.field-error name="image" />
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Enregistrer ma modification</x-ui.button>
        </form>
    </div>
</x-layouts.app>
