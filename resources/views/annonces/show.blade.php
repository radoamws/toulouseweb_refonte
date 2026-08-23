<x-layouts.app :seo="$seo">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.breadcrumb :items="[
            ['label' => 'Annonces', 'href' => '/annonces'],
            ...($classified->category ? [['label' => $classified->category->name, 'href' => '/annonces/'.$classified->category->slug]] : []),
            ['label' => $classified->title],
        ]" />

        <div class="flex flex-wrap items-center gap-2">
            @if ($classified->category)
                <x-ui.badge>{{ $classified->category->name }}</x-ui.badge>
            @endif
            @if ($classified->is_featured)
                <x-ui.badge color="accent">Mise en avant</x-ui.badge>
            @endif
        </div>

        <h1 class="mt-3 font-heading text-3xl font-bold text-ink-900">{{ $classified->title }}</h1>
        @if ($classified->price)
            <p class="mt-2 text-2xl font-bold text-brand-700">{{ number_format($classified->price, 0, ',', ' ') }} €</p>
        @endif

        <div class="prose prose-ink mt-6 max-w-none">{!! nl2br(e($classified->description)) !!}</div>

        <div class="mt-8 rounded-2xl border border-ink-100 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-ink-900">Contact</h2>
            <dl class="mt-3 space-y-2 text-sm">
                @if ($classified->location)
                    <div><dt class="inline font-medium text-ink-500">Localisation :</dt> <dd class="inline text-ink-800">{{ $classified->location }}</dd></div>
                @endif
                @if ($classified->contact_phone)
                    <div><dt class="inline font-medium text-ink-500">Téléphone :</dt> <dd class="inline"><a href="tel:{{ $classified->contact_phone }}" class="text-brand-700 hover:underline" data-track="classified:{{ $classified->id }}:phone_click">{{ $classified->contact_phone }}</a></dd></div>
                @endif
                <div><dt class="inline font-medium text-ink-500">Email :</dt> <dd class="inline"><a href="mailto:{{ $classified->contact_email }}" class="text-brand-700 hover:underline" data-track="classified:{{ $classified->id }}:email_click">{{ $classified->contact_email }}</a></dd></div>
            </dl>
        </div>
    </div>
</x-layouts.app>
