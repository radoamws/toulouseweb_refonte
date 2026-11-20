@php($current = $classified->pending_changes ?? $classified->only(['category_id', 'title', 'description', 'price', 'location', 'contact_phone']))
<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Mes publications', 'href' => route('editeur.mes-publications')], ['label' => 'Modifier mon annonce']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Modifier mon annonce</h1>
        <p class="mt-2 text-ink-600">« {{ $classified->title }} »</p>

        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Votre modification sera revue par notre équipe avant d'être visible en ligne — l'annonce publiée reste inchangée pendant ce temps.
        </div>

        @if ($classified->pending_changes)
            <div class="mt-4 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
                Une modification est déjà en attente de validation. Vous pouvez la corriger ci-dessous, elle remplacera la précédente.
            </div>
        @endif

        <form method="POST" action="{{ route('editeur.annonces.modifier.store', $classified) }}" enctype="multipart/form-data" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="category_id" class="block text-sm font-medium text-ink-700">Catégorie *</label>
                <select name="category_id" id="category_id" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id', $current['category_id']) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="category_id" />
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Titre *</label>
                <input type="text" name="title" id="title" required value="{{ old('title', $current['title']) }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-ink-700">Description *</label>
                <textarea name="description" id="description" rows="5" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('description', $current['description']) }}</textarea>
                <x-ui.field-error name="description" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="price" class="block text-sm font-medium text-ink-700">Prix (€)</label>
                    <input type="number" step="0.01" min="0" name="price" id="price" value="{{ old('price', $current['price'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="price" />
                </div>
                <div>
                    <label for="location" class="block text-sm font-medium text-ink-700">Localisation</label>
                    <input type="text" name="location" id="location" value="{{ old('location', $current['location'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="location" />
                </div>
            </div>

            <div>
                <label for="contact_phone" class="block text-sm font-medium text-ink-700">Téléphone</label>
                <input type="tel" name="contact_phone" id="contact_phone" value="{{ old('contact_phone', $current['contact_phone'] ?? '') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="contact_phone" />
            </div>

            <p class="text-xs text-ink-500">L'email de contact n'est pas modifiable depuis cet espace — contactez-nous si besoin.</p>

            <div>
                <label for="photo" class="block text-sm font-medium text-ink-700">Nouvelle photo (facultatif)</label>
                <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <p class="mt-1 text-xs text-ink-500">JPEG, PNG ou WEBP, 4 Mo maximum. Remplace la photo actuelle une fois la modification validée.</p>
                <x-ui.field-error name="photo" />
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Enregistrer ma modification</x-ui.button>
        </form>
    </div>
</x-layouts.app>
