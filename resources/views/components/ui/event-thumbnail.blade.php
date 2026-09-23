@props(['event'])

{{-- Image par défaut avec le nom de la catégorie centré dessus, quand
l'événement n'a pas de visuel (demande client, 23/09/2026) — remplace la
simple icône neutre affichée jusqu'ici. Couleur de fond = celle de la
catégorie (cohérent avec le reste de l'agenda, voir §46/§52/§54), repli sur
la couleur de marque si l'événement n'a aucune catégorie. Occupe tout
l'espace du conteneur appelant (h-full w-full), qui garde la main sur le
ratio/l'arrondi (aspect-[4/3], aspect-video...). --}}
@if ($event->image_url)
    <img
        src="{{ $event->image_url }}"
        alt="{{ $event->title }}"
        loading="lazy"
        {{ $attributes->merge(['class' => 'h-full w-full object-cover']) }}
    >
@else
    @php $eventCategory = $event->categories->first(); @endphp
    <div
        {{ $attributes->merge(['class' => 'flex h-full w-full items-center justify-center p-4 text-center']) }}
        style="background-color: {{ $eventCategory?->color ?? '#a63f23' }};"
    >
        <span class="font-heading text-base font-bold uppercase tracking-wide text-white sm:text-lg">
            {{ $eventCategory?->name ?? 'Agenda' }}
        </span>
    </div>
@endif
