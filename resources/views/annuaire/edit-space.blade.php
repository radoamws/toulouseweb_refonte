<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Annuaire', 'href' => '/annuaire'], ['label' => 'Mon espace']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Gérer ma fiche</h1>
        <p class="mt-2 text-ink-600">« {{ $listing->title }} »</p>

        @if ($listing->pending_changes)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Une modification est déjà en attente de validation par notre équipe. Vous pouvez la corriger ci-dessous, elle remplacera la précédente.
            </div>
        @endif

        {{-- Paiement Stripe + essai gratuit (demande client, 24/11/2026,
        rectifiée en abonnement annuel le 25/11/2026, voir
        Listing::hasPremiumFeatures()/isPaid()/isTrialing()/isLapsedPaid() et
        TECHNICAL_DOCUMENTATION.md §83). --}}
        @if ($listing->isPaid())
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <p>
                    Fiche payante — merci pour votre confiance ! Vos options complètes (photo, description, site web,
                    réservation) et votre référencement boosté sont actifs jusqu'au
                    <strong>{{ $listing->premium_until->translatedFormat('d F Y') }}</strong>.
                </p>
                <form method="POST" action="{{ route('annuaire.listings.checkout', $listing->edit_token) }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm">Renouveler maintenant (+1 an)</x-ui.button>
                </form>
            </div>
        @else
            <div class="mt-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-ink-700">
                @if ($listing->isLapsedPaid())
                    <p>
                        Votre abonnement annuel est arrivé à échéance : votre fiche est repassée en version
                        simplifiée (nom, coordonnées, adresse) en attendant son renouvellement.
                    </p>
                @elseif ($listing->isTrialing())
                    <p>
                        Votre fiche profite de ses options complètes (photo, description, site web, réservation)
                        jusqu'au <strong>{{ $listing->premium_until->translatedFormat('d F Y') }}</strong>. Passé ce
                        délai, elles disparaîtront sauf passage à la formule payante.
                    </p>
                @else
                    <p>
                        Votre essai gratuit est terminé : votre fiche est actuellement en version simplifiée (nom,
                        coordonnées, adresse).
                    </p>
                @endif
                <p class="mt-2">
                    <strong>100€ / an</strong> pour profiter de ces options en continu + un référencement boosté
                    (votre fiche remonte en tête des résultats de recherche de l'annuaire).
                </p>
                <form method="POST" action="{{ route('annuaire.listings.checkout', $listing->edit_token) }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="primary" size="sm">Payer 100€ pour 1 an</x-ui.button>
                </form>
            </div>
        @endif

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @php($current = $listing->pending_changes ?? $listing->only(['title', 'address', 'city', 'postal_code', 'phone', 'email', 'short_description', 'description', 'website', 'reservation_url']))

        <form method="POST" action="{{ route('annuaire.updateEditSpace', $listing->edit_token) }}" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="url_verification">Laisser vide</label>
                <input type="text" name="url_verification" id="url_verification" tabindex="-1" autocomplete="off">
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

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="phone" class="block text-sm font-medium text-ink-700">Téléphone</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone', $current['phone'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="phone" />
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-ink-700">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $current['email'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="email" />
                </div>
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

            <p class="text-xs text-ink-500">La catégorie, la formule et le logo ne sont pas modifiables depuis cet espace pour le moment — contactez-nous si besoin.</p>

            <x-ui.button type="submit" variant="primary" size="lg">Enregistrer ma modification</x-ui.button>
        </form>
    </div>
</x-layouts.app>
