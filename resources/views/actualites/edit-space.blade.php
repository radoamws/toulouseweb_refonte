<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[['label' => 'Actualités', 'href' => '/actualites'], ['label' => 'Mon espace']]" />

        <h1 class="font-heading text-3xl font-bold text-ink-900">Gérer ma proposition</h1>
        <p class="mt-2 text-ink-600">« {{ $news->title }} »</p>

        @if ($news->pending_changes)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Une modification est déjà en attente de validation par notre équipe. Vous pouvez la corriger ci-dessous, elle remplacera la précédente.
            </div>
        @endif

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @php($current = $news->pending_changes ?? $news->only(['title', 'excerpt', 'body', 'schedule', 'address', 'price', 'website']) + ['start_date' => optional($news->start_date)->format('Y-m-d'), 'end_date' => optional($news->end_date)->format('Y-m-d')])

        <form method="POST" action="{{ route('actualites.updateEditSpace', $news->edit_token) }}" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <x-ui.form-errors />

            {{-- Honeypot anti-spam : invisible pour un humain, un bot le remplit souvent --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="url_verification">Laisser vide</label>
                <input type="text" name="url_verification" id="url_verification" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-ink-700">Titre *</label>
                <input type="text" name="title" id="title" required value="{{ old('title', $current['title']) }}"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="title" />
            </div>

            <div>
                <label for="excerpt" class="block text-sm font-medium text-ink-700">Résumé court</label>
                <input type="text" name="excerpt" id="excerpt" value="{{ old('excerpt', $current['excerpt'] ?? '') }}" maxlength="255"
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <x-ui.field-error name="excerpt" />
            </div>

            <div>
                <label for="body" class="block text-sm font-medium text-ink-700">Texte de l'actualité *</label>
                <textarea name="body" id="body" rows="8" required
                    class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">{{ old('body', $current['body']) }}</textarea>
                <x-ui.field-error name="body" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-ink-700">Date de début *</label>
                    <input type="date" name="start_date" id="start_date" required value="{{ old('start_date', $current['start_date']) }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="start_date" />
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-ink-700">Date de fin *</label>
                    <input type="date" name="end_date" id="end_date" required value="{{ old('end_date', $current['end_date']) }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="end_date" />
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="schedule" class="block text-sm font-medium text-ink-700">Horaire</label>
                    <input type="text" name="schedule" id="schedule" value="{{ old('schedule', $current['schedule'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="schedule" />
                </div>
                <div>
                    <label for="address" class="block text-sm font-medium text-ink-700">Adresse</label>
                    <input type="text" name="address" id="address" value="{{ old('address', $current['address'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="address" />
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="price" class="block text-sm font-medium text-ink-700">Tarif</label>
                    <input type="text" name="price" id="price" value="{{ old('price', $current['price'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="price" />
                </div>
                <div>
                    <label for="website" class="block text-sm font-medium text-ink-700">Site web</label>
                    <input type="url" name="website" id="website" value="{{ old('website', $current['website'] ?? '') }}" placeholder="https://"
                        class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <x-ui.field-error name="website" />
                </div>
            </div>

            <p class="text-xs text-ink-500">La catégorie et la photo ne sont pas modifiables depuis cet espace pour le moment — contactez-nous si besoin.</p>

            <x-ui.button type="submit" variant="primary" size="lg">Enregistrer ma modification</x-ui.button>
        </form>
    </div>
</x-layouts.app>
