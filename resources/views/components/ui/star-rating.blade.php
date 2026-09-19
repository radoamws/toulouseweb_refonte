@props(['rating' => null, 'count' => null])

{{-- Affichage lecture seule d'une note moyenne (demande client, 19/09/2026 —
bonne pratique des sites de cinéma type AlloCiné : note + nombre d'avis à
côté du titre). Étoiles pleines/vides en caractères Unicode (pas d'icône
externe) — arrondi à l'étoile la plus proche, pas d'affichage à moitié pleine. --}}
@php
    $rounded = $rating !== null ? (int) round($rating) : null;
@endphp

@if ($rating !== null)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-amber-500']) }}>
        <span aria-hidden="true">
            @for ($i = 1; $i <= 5; $i++)
                {{ $i <= $rounded ? '★' : '☆' }}
            @endfor
        </span>
        <span class="text-sm font-semibold text-ink-700">{{ number_format($rating, 1) }}</span>
        @if ($count !== null)
            <span class="text-sm text-ink-400">({{ $count }} avis)</span>
        @endif
    </span>
@endif
