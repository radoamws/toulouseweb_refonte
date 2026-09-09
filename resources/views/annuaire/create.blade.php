<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Annuaire', 'href' => '/annuaire'], ['label' => 'Ajouter mon établissement']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Ajouter mon établissement</h1>
        <p class="mt-2 text-ink-600">
            Votre fiche sera vérifiée par notre équipe avant publication — comptez généralement moins de 24h,
            quelle que soit la formule choisie ci-dessous.
        </p>

        <form method="POST" action="{{ route('annuaire.store') }}" class="mt-8 space-y-5"
            x-data="{ tier: '{{ old('tier', 'free') }}' }">
            @csrf

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="url_verification">Laisser vide</label>
                <input type="text" name="url_verification" id="url_verification" tabindex="-1" autocomplete="off">
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-ink-700">Formule *</legend>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="tier" value="free" x-model="tier" class="mt-1" @checked(old('tier', 'free') === 'free')>
                        <span>
                            <span class="block text-sm font-medium text-ink-900">Gratuite</span>
                            <span class="block text-xs text-ink-500">Fiche simplifiée : nom, coordonnées, adresse.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="tier" value="paid" x-model="tier" class="mt-1" @checked(old('tier') === 'paid')>
                        <span>
                            <span class="block text-sm font-medium text-ink-900">Payante</span>
                            <span class="block text-xs text-ink-500">Fiche complète : description, site web, réservation… — soumise à validation comme la fiche gratuite.</span>
                        </span>
                    </label>
                </div>
                <x-ui.field-error name="tier" />
            </fieldset>

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

            {{-- Champs de la fiche "complète" — visibles seulement pour la formule
            payante (même répartition que ListingResource côté admin), mais
            toujours envoyés/acceptés même si masqués (voir ListingController::store()). --}}
            <div x-show="tier === 'paid'" x-cloak class="space-y-5 rounded-lg border border-brand-100 bg-brand-50/40 p-4">
                <p class="text-sm font-medium text-ink-700">Contenu de la fiche complète</p>

                <div>
                    <label for="short_description" class="block text-sm font-medium text-ink-700">Accroche</label>
                    <input type="text" name="short_description" id="short_description" value="{{ old('short_description') }}" maxlength="255"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="short_description" />
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-ink-700">Description</label>
                    <textarea name="description" id="description" rows="5"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('description') }}</textarea>
                    <x-ui.field-error name="description" />
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="website" class="block text-sm font-medium text-ink-700">Site web</label>
                        <input type="url" name="website" id="website" value="{{ old('website') }}" placeholder="https://"
                            class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                        <x-ui.field-error name="website" />
                    </div>
                    <div>
                        <label for="reservation_url" class="block text-sm font-medium text-ink-700">Lien de réservation</label>
                        <input type="url" name="reservation_url" id="reservation_url" value="{{ old('reservation_url') }}" placeholder="https://"
                            class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                        <x-ui.field-error name="reservation_url" />
                    </div>
                </div>
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Envoyer ma fiche</x-ui.button>
        </form>
    </div>
</x-layouts.app>
