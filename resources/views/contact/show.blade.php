<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Contact']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Contactez-nous</h1>
        <p class="mt-2 text-ink-600">
            Une question, une suggestion, une envie de collaborer ? Écrivez-nous, nous vous répondons rapidement.
        </p>

        <form method="POST" action="{{ route('contact.store') }}" class="mt-8 space-y-5">
            @csrf

            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Laisser vide</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium text-ink-700">Nom *</label>
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
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="phone" class="block text-sm font-medium text-ink-700">Téléphone</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="phone" />
                </div>
                <div>
                    <label for="subject" class="block text-sm font-medium text-ink-700">Sujet</label>
                    <input type="text" name="subject" id="subject" value="{{ old('subject') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="subject" />
                </div>
            </div>

            <div>
                <label for="message" class="block text-sm font-medium text-ink-700">Message *</label>
                <textarea name="message" id="message" rows="6" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('message') }}</textarea>
                <x-ui.field-error name="message" />
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Envoyer le message</x-ui.button>
        </form>
    </div>
</x-layouts.app>
