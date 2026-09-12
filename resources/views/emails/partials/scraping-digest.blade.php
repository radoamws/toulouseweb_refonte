<h2>{{ $entityLabel }} : ça bouge sur ToulouseWeb</h2>
<p>
    @if ($totals['created'] > 0)
        {{ $totals['created'] }} nouveauté(s)
    @endif
    @if ($totals['created'] > 0 && $totals['updated'] > 0)
        et
    @endif
    @if ($totals['updated'] > 0)
        {{ $totals['updated'] }} mise(s) à jour
    @endif
    à découvrir dès maintenant.
</p>
<p><a href="{{ $url }}">Voir sur ToulouseWeb →</a></p>
