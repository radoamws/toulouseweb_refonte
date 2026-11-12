<h2>Les nouveautés du jour sur ToulouseWeb</h2>
<p>Voici ce qui vient d'être publié et validé par notre équipe :</p>

@foreach ($items as $category => $entries)
<h3>{{ $category }}</h3>
<ul>
@foreach ($entries as $entry)
<li>
<strong><a href="{{ $entry['url'] }}">{{ $entry['title'] }}</a></strong>
@if ($entry['excerpt'])
<br>{{ $entry['excerpt'] }}
@endif
</li>
@endforeach
</ul>
@endforeach

<p style="text-align:center;margin:32px 0;">
<a href="{{ url('/') }}" style="background-color:#CC0000;color:#ffffff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Découvrir ToulouseWeb</a>
</p>
<p>À très vite,<br>L'équipe ToulouseWeb</p>
