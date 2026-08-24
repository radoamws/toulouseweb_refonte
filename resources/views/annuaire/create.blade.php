<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Annuaire', 'href' => '/annuaire'], ['label' => 'Ajouter mon établissement']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Ajouter mon établissement</h1>
        <p class="mt-2 text-ink-600">
            Votre fiche sera vérifiée par notre équipe avant publication — comptez généralement moins de 24h.
            Pour enrichir votre fiche (description détaillée, photos, liens de réservation…), contactez-nous après validation.
        </p>

        <form method="POST" action="{{ route('annuaire.store') }}" class="mt-8 space-y-5">
            @csrf

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="url_verification">Laisser vide</label>
                <input type="text" name="url_verification" id="url_verification" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="category_id" class="block text-sm font-medium text-ink-700">Catégorie *</label>
                <select name="category_id" id="category_id" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <option value="">Choisir…</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>
                            {{ str_repeat('— ', $cat->level) }}{{ $cat->name }}
                        </option>
                    @endforeach
                </select>
                <x-ui.field-error name="category_id" />
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Nom de l'établissement *</label>
                <input type="text" name="title" id="title" required value="{{ old('title') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div>
                <label for="short_description" class="block text-sm font-medium text-ink-700">Courte présentation</label>
                <input type="text" name="short_description" id="short_description" value="{{ old('short_description') }}" maxlength="255"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="short_description" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="address" class="block text-sm font-medium text-ink-700">Adresse</label>
                    <input type="text" name="address" id="address" value="{{ old('address') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="address" />
                </div>
                <div>
                    <label for="city" class="block text-sm font-medium text-ink-700">Ville</label>
                    <input type="text" name="city" id="city" value="{{ old('city') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="city" />
                </div>
                <div>
                    <label for="postal_code" class="block text-sm font-medium text-ink-700">Code postal</label>
                    <input type="text" name="postal_code" id="postal_code" value="{{ old('postal_code') }}" maxlength="10"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="postal_code" />
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="phone" class="block text-sm font-medium text-ink-700">Téléphone</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="phone" />
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-ink-700">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="email" />
                </div>
            </div>

            <div>
                <label for="website" class="block text-sm font-medium text-ink-700">Site web</label>
                <input type="url" name="website" id="website" value="{{ old('website') }}" placeholder="https://"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="website" />
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Envoyer ma fiche</x-ui.button>
        </form>
    </div>
</x-layouts.app>
