@php($current = $listing->pending_changes ?? $listing->only(['title', 'tier', 'address', 'city', 'postal_code', 'phone', 'short_description', 'description', 'website', 'reservation_url']) + ['category_id' => $listing->categories->first()?->id])
<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Mes publications', 'href' => route('editeur.mes-publications')], ['label' => 'Modifier ma fiche']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Modifier ma fiche</h1>
        <p class="mt-2 text-ink-600">« {{ $listing->title }} »</p>

        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Votre modification sera revue par notre équipe avant d'être visible en ligne — la fiche publiée reste inchangée pendant ce temps.
        </div>

        @if ($listing->pending_changes)
            <div class="mt-4 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
                Une modification est déjà en attente de validation. Vous pouvez la corriger ci-dessous, elle remplacera la précédente.
            </div>
        @endif

        <form method="POST" action="{{ route('editeur.annuaire.modifier.store', $listing) }}" enctype="multipart/form-data" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="url_verification">Laisser vide</label>
                <input type="text" name="url_verification" id="url_verification" tabindex="-1" autocomplete="off">
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-ink-700">Formule *</legend>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="tier" value="free" class="mt-1" @checked(old('tier', $current['tier']) === 'free')>
                        <span class="block text-sm font-medium text-ink-900">Gratuite</span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="tier" value="paid" class="mt-1" @checked(old('tier', $current['tier']) === 'paid')>
                        <span class="block text-sm font-medium text-ink-900">Payante</span>
                    </label>
                </div>
                <x-ui.field-error name="tier" />
            </fieldset>

            <div>
                <label for="category_id" class="block text-sm font-medium text-ink-700">Catégorie *</label>
                <select name="category_id" id="category_id" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id', $current['category_id']) == $cat->id)>
                            {{ str_repeat('— ', $cat->level) }}{{ $cat->name }}
                        </option>
                    @endforeach
                </select>
                <x-ui.field-error name="category_id" />
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Nom de l'établissement *</label>
                <input type="text" name="title" id="title" required value="{{ old('title', $current['title']) }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="address" class="block text-sm font-medium text-ink-700">Adresse</label>
                    <input type="text" name="address" id="address" value="{{ old('address', $current['address'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="address" />
                </div>
                <div>
                    <label for="city" class="block text-sm font-medium text-ink-700">Ville</label>
                    <input type="text" name="city" id="city" value="{{ old('city', $current['city'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="city" />
                </div>
                <div>
                    <label for="postal_code" class="block text-sm font-medium text-ink-700">Code postal</label>
                    <input type="text" name="postal_code" id="postal_code" value="{{ old('postal_code', $current['postal_code'] ?? '') }}" maxlength="10"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="postal_code" />
                </div>
            </div>

            <div>
                <label for="phone" class="block text-sm font-medium text-ink-700">Téléphone</label>
                <input type="tel" name="phone" id="phone" value="{{ old('phone', $current['phone'] ?? '') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="phone" />
            </div>

            <div>
                <label for="short_description" class="block text-sm font-medium text-ink-700">Accroche</label>
                <input type="text" name="short_description" id="short_description" value="{{ old('short_description', $current['short_description'] ?? '') }}" maxlength="255"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="short_description" />
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-ink-700">Description</label>
                <textarea name="description" id="description" rows="5"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('description', $current['description'] ?? '') }}</textarea>
                <x-ui.field-error name="description" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="website" class="block text-sm font-medium text-ink-700">Site web</label>
                    <input type="url" name="website" id="website" value="{{ old('website', $current['website'] ?? '') }}" placeholder="https://"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="website" />
                </div>
                <div>
                    <label for="reservation_url" class="block text-sm font-medium text-ink-700">Lien de réservation</label>
                    <input type="url" name="reservation_url" id="reservation_url" value="{{ old('reservation_url', $current['reservation_url'] ?? '') }}" placeholder="https://"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="reservation_url" />
                </div>
            </div>

            <div>
                <label for="logo" class="block text-sm font-medium text-ink-700">Nouveau logo / photo (facultatif)</label>
                <input type="file" name="logo" id="logo" accept="image/jpeg,image/png,image/webp"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <p class="mt-1 text-xs text-ink-500">JPEG, PNG ou WEBP, 4 Mo maximum. Remplace le logo actuel une fois la modification validée.</p>
                <x-ui.field-error name="logo" />
            </div>

            <p class="text-xs text-ink-500">L'email de contact n'est pas modifiable depuis cet espace — contactez-nous si besoin.</p>

            <x-ui.button type="submit" variant="primary" size="lg">Enregistrer ma modification</x-ui.button>
        </form>
    </div>
</x-layouts.app>
