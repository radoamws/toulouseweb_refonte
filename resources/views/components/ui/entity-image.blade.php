{{--
    Image d'une entité (fiche annuaire, annonce, actualité, film...) avec
    repli sur l'image de marque ToulouseWeb par défaut (demande client,
    01/10/2026 : "l'image par défaut pour toutes les encadrés de toutes les
    entités confondus"). Remplace au fur et à mesure l'icône neutre/le vide
    précédemment affiché quand une entité n'a pas d'image — y compris les
    fiches annuaire GRATUITES (qui n'en ont jamais, brief §5).

    `object-contain` (jamais `object-cover`) sur le repli : la largeur ne
    doit jamais se tronquer pour ne pas couper le texte "toulouseweb.com" de
    l'image — les encadrés n'ont pas tous le même format (carré, 4/3, 2/3
    pour les affiches de films...), `object-contain` garantit l'image
    entière visible quel que soit le ratio du conteneur, quitte à laisser un
    léger bord (`bg-brand-900`, assorti au fond rouge sombre de l'image elle-même).

    `$attributes` (class, etc.) s'applique à l'élément visuel dans les deux
    cas — un `<img>` object-cover normal quand `$src` existe, le conteneur
    du repli sinon (toujours `h-full w-full` pour remplir le conteneur
    parent à ratio fixe) : les deux doivent remplir le même espace.
--}}
@props(['src' => null, 'alt' => ''])

@if ($src)
    <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" {{ $attributes->merge(['class' => 'h-full w-full object-cover']) }}>
@else
    <div {{ $attributes->merge(['class' => 'flex h-full w-full items-center justify-center bg-brand-900']) }}>
        <img src="{{ asset('branding/default-card-image.png') }}" alt="ToulouseWeb" loading="lazy" class="h-[80%] w-[80%] object-contain">
    </div>
@endif
