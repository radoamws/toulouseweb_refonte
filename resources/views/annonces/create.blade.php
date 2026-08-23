<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Annonces', 'href' => '/annonces'], ['label' => 'Déposer une annonce']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Déposer une annonce</h1>
        <p class="mt-2 text-ink-600">
            Votre annonce sera vérifiée par notre équipe avant publication — comptez généralement moins de 24h.
        </p>

        <form method="POST" action="{{ route('annonces.store') }}" class="mt-8 space-y-5">
            @csrf

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="category_id" class="block text-sm font-medium text-ink-700">Catégorie *</label>
                <select name="category_id" id="category_id" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <option value="">Choisir…</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="category_id" />
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Titre *</label>
                <input type="text" name="title" id="title" required value="{{ old('title') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-ink-700">Description *</label>
                <textarea name="description" id="description" rows="5" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('description') }}</textarea>
                <x-ui.field-error name="description" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="price" class="block text-sm font-medium text-ink-700">Prix (€)</label>
                    <input type="number" step="0.01" min="0" name="price" id="price" value="{{ old('price') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="price" />
                </div>
                <div>
                    <label for="location" class="block text-sm font-medium text-ink-700">Localisation</label>
                    <input type="text" name="location" id="location" value="{{ old('location') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="location" />
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="contact_email" class="block text-sm font-medium text-ink-700">Email de contact *</label>
                    <input type="email" name="contact_email" id="contact_email" required value="{{ old('contact_email') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="contact_email" />
                </div>
                <div>
                    <label for="contact_phone" class="block text-sm font-medium text-ink-700">Téléphone</label>
                    <input type="tel" name="contact_phone" id="contact_phone" value="{{ old('contact_phone') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="contact_phone" />
                </div>
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Envoyer mon annonce</x-ui.button>
        </form>
    </div>
</x-layouts.app>
