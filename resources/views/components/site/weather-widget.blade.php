{{--
    Widget météo + qualité de l'air de la home (brief roadmap "contenu",
    14/10/2026, demande client : "widget simple... forte valeur d'usage
    quotidien" ; mis en avant visuellement le 14/10/2026, 2e demande client :
    "met plus en valeur la partie météo" — bandeau pleine largeur dégradé
    (même traitement visuel qu'un hero, pas une simple carte parmi
    d'autres) plutôt que la carte blanche discrète d'origine).
    `$weather` peut être `null` (Open-Meteo indisponible, voir
    App\Services\Weather\OpenMeteoService::current()) — le composant ne
    rend alors RIEN plutôt qu'un bandeau vide/cassé.
--}}
@props(['weather'])

@if ($weather)
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-brand-800 px-6 py-8 shadow-lg sm:px-10 sm:py-10">
        {{-- Icône géante décorative en fond, à moitié hors cadre — purement
        visuel (aria-hidden), la même icône "au propre" reste à gauche pour
        l'information réelle. --}}
        <div class="pointer-events-none absolute -right-6 -top-10 text-white/10" aria-hidden="true">
            <x-site.weather-icon :icon="$weather['icon']" class="h-48 w-48 sm:h-56 sm:w-56" />
        </div>

        <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-5">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-white sm:h-20 sm:w-20">
                    <x-site.weather-icon :icon="$weather['icon']" class="h-10 w-10 sm:h-12 sm:w-12" />
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-white/70">Météo à Toulouse</p>
                    <p class="font-heading text-4xl font-extrabold leading-none text-white sm:text-5xl">{{ $weather['temperature'] }}°C</p>
                    <p class="mt-1 text-base font-medium text-white/90">{{ $weather['description'] }}</p>
                </div>
            </div>

            @if ($weather['aqi'] !== null)
                <div class="shrink-0 rounded-2xl bg-white/15 px-5 py-3 text-center sm:text-right">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-white/70">Qualité de l'air</p>
                    <p class="font-heading text-xl font-bold text-white">{{ $weather['aqi_label'] }}</p>
                </div>
            @endif
        </div>
    </div>
@endif
