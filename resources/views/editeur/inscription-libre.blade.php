<x-layouts.app :seo="[]">
    <div class="mx-auto max-w-md px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="font-heading text-3xl font-bold text-ink-900">Créer mon espace</h1>
        <p class="mt-2 text-ink-600">
            Créez votre compte pour déposer et gérer vos publications ToulouseWeb (annonces, événements, actualités, fiches annuaire).
        </p>

        <form method="POST" action="{{ route('editeur.inscription.libre.store') }}" data-recaptcha-action="editeur_inscription" class="mt-8 space-y-5">
            @csrf

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-ink-700">Votre nom *</label>
                <input type="text" name="name" id="name" required value="{{ old('name') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="name" />
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-ink-700">Email *</label>
                <input type="email" name="email" id="email" required value="{{ old('email') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="email" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-ink-700">Mot de passe *</label>
                <input type="password" name="password" id="password" required minlength="8"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <p class="mt-1 text-xs text-ink-500">8 caractères minimum.</p>
                <x-ui.field-error name="password" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-ink-700">Confirmer le mot de passe *</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required minlength="8"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Créer mon espace</x-ui.button>
        </form>

        <p class="mt-4 text-sm text-ink-600">
            Déjà un compte ? <a href="{{ route('editeur.connexion') }}" class="text-brand-600 hover:underline">Se connecter</a>
        </p>
    </div>
</x-layouts.app>
