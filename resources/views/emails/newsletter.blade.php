<x-mail::message>
{!! $newsletter->body_html !!}

@if ($newsletter->preview_text)
<!-- {{ $newsletter->preview_text }} -->
@endif

---

<p style="font-size:12px;color:#94a3b8;">
Vous recevez cet email car vous êtes inscrit à la newsletter {{ config('app.name') }}.
<a href="{{ $unsubscribeUrl }}">Se désinscrire</a>
</p>
</x-mail::message>
