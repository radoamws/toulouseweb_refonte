@php
    $partners = \App\Models\PartnerSite::query()->orderBy('order')->limit(6)->get();
    $siteSettings = \App\Models\SiteSetting::current();
    $socialLabels = [
        'facebook_url' => 'Facebook', 'instagram_url' => 'Instagram', 'twitter_url' => 'X / Twitter',
        'linkedin_url' => 'LinkedIn', 'youtube_url' => 'YouTube',
    ];
@endphp
<footer class="mt-16 border-t border-ink-100 bg-ink-900 text-ink-200">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 md:grid-cols-4">
            <div>
                @if ($siteSettings->logo_url)
                    {{-- Fond sombre du footer : le vrai logo ToulouseWeb
                    (texte blanc) s'y affiche nativement, contrairement à
                    l'entête clair (voir components/site/header.blade.php). --}}
                    <img src="{{ $siteSettings->logo_url }}" alt="{{ $siteSettings->site_name }}" class="h-8 w-auto">
                @else
                    <p class="font-heading text-lg font-bold text-white">{{ $siteSettings->site_name }}</p>
                @endif
                <p class="mt-3 text-sm text-ink-300">
                    {{ $siteSettings->description ?: 'Le portail pour découvrir Toulouse et sa région : actualités, agenda, cinéma, annuaire et annonces locales.' }}
                </p>
                @if ($siteSettings->socialLinks())
                    <ul class="mt-4 flex flex-wrap gap-3 text-sm">
                        @foreach (['facebook_url', 'instagram_url', 'twitter_url', 'linkedin_url', 'youtube_url'] as $field)
                            @if ($siteSettings->$field)
                                <li><a href="{{ $siteSettings->$field }}" target="_blank" rel="noopener" class="hover:text-white">{{ $socialLabels[$field] }}</a></li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <p class="text-sm font-semibold text-white">Découvrir</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="/actualites" class="hover:text-white">Actualités</a></li>
                    <li><a href="/agenda" class="hover:text-white">Agenda</a></li>
                    <li><a href="/cinema" class="hover:text-white">Cinéma</a></li>
                    <li><a href="/annuaire" class="hover:text-white">Annuaire</a></li>
                    <li><a href="/annonces" class="hover:text-white">Annonces</a></li>
                </ul>
            </div>
            <div>
                <p class="text-sm font-semibold text-white">Informations</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="/contact" class="hover:text-white">Contact</a></li>
                    <li><a href="/mentions-legales" class="hover:text-white">Mentions légales</a></li>
                    <li><a href="/confidentialite" class="hover:text-white">Confidentialité</a></li>
                </ul>
            </div>
            @if ($partners->isNotEmpty())
                <div>
                    <p class="text-sm font-semibold text-white">Nos portails partenaires</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($partners as $partner)
                            <li>
                                <a href="{{ $partner->url }}" rel="noopener" target="_blank" class="hover:text-white"
                                   data-track="partner_site:{{ $partner->id }}:footer">
                                    {{ $partner->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="mt-10 flex flex-col items-center justify-between gap-3 border-t border-ink-700 pt-6 text-xs text-ink-400 sm:flex-row">
            <p>&copy; {{ now()->year }} {{ $siteSettings->site_name }} — Tous droits réservés.</p>
            <p>Toulouse et sa région, autrement.</p>
        </div>
    </div>
</footer>
