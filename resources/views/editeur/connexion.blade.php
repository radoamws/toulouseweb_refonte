<x-layouts.app :seo="[]">
    <div class="mx-auto max-w-md px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="font-heading text-3xl font-bold text-ink-900">Connexion</h1>
        <p class="mt-2 text-ink-600">Accédez à votre espace pour gérer vos publications ToulouseWeb.</p>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('editeur.connexion.store') }}" data-recaptcha-action="editeur_connexion" class="mt-8 space-y-5">
            @csrf

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-ink-700">Email *</label>
                <input type="email" name="email" id="email" required value="{{ old('email') }}" autofocus
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="email" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-ink-700">Mot de passe *</label>
                <input type="password" name="password" id="password" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="password" />
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-ink-600">
                    <input type="checkbox" name="remember">
                    Se souvenir de moi
                </label>
                <a href="{{ route('editeur.mot-de-passe.oublie') }}" class="text-brand-600 hover:underline">Mot de passe oublié ?</a>
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Se connecter</x-ui.button>
        </form>

        <p class="mt-4 text-sm text-ink-600">
            Pas encore de compte ? <a href="{{ route('editeur.inscription.libre') }}" class="text-brand-600 hover:underline">S'inscrire</a>
        </p>
    </div>
</x-layouts.app>
