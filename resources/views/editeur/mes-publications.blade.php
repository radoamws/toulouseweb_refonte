@php
    $statusLabels = [
        'draft' => 'Brouillon', 'pending' => 'En attente de validation', 'published' => 'Publiée',
        'rejected' => 'Refusée', 'expired' => 'Expirée', 'archived' => 'Archivée', 'cancelled' => 'Annulée',
    ];
@endphp
<x-layouts.app :seo="[]">
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl font-bold text-ink-900">Mes publications</h1>
                <p class="mt-2 text-ink-600">Bonjour {{ $editor->name }}.</p>
            </div>
            <form method="POST" action="{{ route('editeur.deconnexion') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Déconnexion</x-ui.button>
            </form>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Toute modification que vous enregistrez ici est revue par notre équipe avant d'être visible en ligne — votre publication actuelle reste inchangée pendant ce temps.
        </div>

        @foreach ([
            ['label' => 'Annonces', 'items' => $classifieds, 'editRoute' => 'editeur.annonces.modifier', 'editParam' => fn ($item) => $item],
            ['label' => 'Événements', 'items' => $events, 'editRoute' => 'editeur.agenda.modifier', 'editParam' => fn ($item) => $item],
            ['label' => 'Actualités', 'items' => $news, 'editRoute' => 'editeur.actualites.modifier', 'editParam' => fn ($item) => $item],
            ['label' => 'Fiches annuaire', 'items' => $listings, 'editRoute' => 'editeur.annuaire.modifier', 'editParam' => fn ($item) => $item],
        ] as $group)
            @if ($group['items']->isNotEmpty())
                <section class="mt-8">
                    <h2 class="font-heading text-xl font-semibold text-ink-900">{{ $group['label'] }}</h2>
                    <div class="mt-3 divide-y divide-ink-100 rounded-lg border border-ink-200">
                        @foreach ($group['items'] as $item)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <p class="font-medium text-ink-900">{{ $item->title }}</p>
                                    <p class="text-sm text-ink-500">
                                        {{ $statusLabels[$item->status] ?? $item->status }}
                                        @if ($item->pending_changes)
                                            <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Modification en attente</span>
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route($group['editRoute'], $group['editParam']($item)) }}" class="text-sm font-medium text-brand-600 hover:underline">Modifier</a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach

        @if ($classifieds->isEmpty() && $events->isEmpty() && $news->isEmpty() && $listings->isEmpty())
            <p class="mt-8 text-ink-600">Aucune publication rattachée à votre compte pour le moment.</p>
        @endif
    </div>
</x-layouts.app>
