<x-layouts.app :seo="[]">
    <div class="mx-auto max-w-md px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="font-heading text-3xl font-bold text-ink-900">Nouveau mot de passe</h1>

        <form method="POST" action="{{ route('editeur.mot-de-passe.reinitialiser.store') }}" class="mt-8 space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-ui.form-errors />

            <div>
                <label for="email" class="block text-sm font-medium text-ink-700">Email *</label>
                <input type="email" name="email" id="email" required value="{{ old('email', $email) }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="email" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-ink-700">Nouveau mot de passe *</label>
                <input type="password" name="password" id="password" required minlength="8"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="password" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-ink-700">Confirmer le mot de passe *</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required minlength="8"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Réinitialiser mon mot de passe</x-ui.button>
        </form>
    </div>
</x-layouts.app>
