<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Actualités', 'href' => '/actualites'], ['label' => 'Proposer une actualité']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Proposer une actualité</h1>
        <p class="mt-2 text-ink-600">
            Votre proposition sera vérifiée par notre équipe avant publication — comptez généralement moins de 24h.
        </p>

        <form method="POST" action="{{ route('actualites.store') }}" class="mt-8 space-y-5">
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
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
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
                <label for="excerpt" class="block text-sm font-medium text-ink-700">Résumé court</label>
                <input type="text" name="excerpt" id="excerpt" value="{{ old('excerpt') }}" maxlength="255"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="excerpt" />
            </div>

            <div>
                <label for="body" class="block text-sm font-medium text-ink-700">Texte de l'actualité *</label>
                <textarea name="body" id="body" rows="8" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('body') }}</textarea>
                <x-ui.field-error name="body" />
            </div>

            <div>
                <label for="submitter_email" class="block text-sm font-medium text-ink-700">Votre email *</label>
                <input type="email" name="submitter_email" id="submitter_email" required value="{{ old('submitter_email') }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <p class="mt-1 text-xs text-ink-500">Non publié — sert uniquement à notre équipe pour vous recontacter si besoin.</p>
                <x-ui.field-error name="submitter_email" />
            </div>

            <x-ui.button type="submit" variant="primary" size="lg">Envoyer ma proposition</x-ui.button>
        </form>
    </div>
</x-layouts.app>
