@props([
    'seo' => [],
])

@php
    $siteSettings = \App\Models\SiteSetting::current();
    $seo = array_merge([
        'title' => $siteSettings->site_name.($siteSettings->tagline ? ' — '.$siteSettings->tagline : ' — Toulouse et sa région'),
        'description' => $siteSettings->description ?: "Actualités, agenda, cinéma, annuaire, annonces et sorties à Toulouse et dans sa région.",
        'canonical_url' => url()->current(),
        'robots' => 'index,follow',
        // ⚠️ Bug réel trouvé et corrigé (08/09/2026, audit SEO final,
        // TECHNICAL_DOCUMENTATION.md §24) : ce repli pointait vers
        // `public/images/og-default.jpg`, un fichier qui n'a jamais existé
        // (404 confirmé en direct) — chaque page sans image dédiée
        // (dont la home) partageait donc un aperçu de lien cassé sur les
        // réseaux sociaux. Repli sur l'icône de marque déjà présente en
        // dépôt (voir favicon plus haut) : un vrai visuel, pas un 404 — mais
        // 180×180 (favicon) est loin du format bannière 1200×630 attendu
        // pour un partage social optimal. Reste à fournir par le client : un
        // vrai visuel OG dédié (bannière, pas un simple logo/favicon).
        'og_image' => $siteSettings->default_og_image_url ?: asset('branding/toulouseweb-icon.png'),
    ], array_filter($seo));
@endphp
<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Favicon réel ToulouseWeb (la coccinelle de la marque, voir
    TECHNICAL_DOCUMENTATION.md §13) — remplace `public/favicon.ico`
    (fallback générique Laravel toujours en place pour les navigateurs qui
    ignorent ces balises et redemandent /favicon.ico directement). --}}
    <link rel="icon" type="image/png" href="{{ asset('branding/toulouseweb-icon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('branding/toulouseweb-icon.png') }}">

    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical_url'] }}">

    {{-- Open Graph / Twitter Card — voir brief §13 --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteSettings->site_name }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical_url'] }}">
    @if ($seo['og_image'])
        <meta property="og:image" content="{{ $seo['og_image'] }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    {{-- Manquant avant ce correctif (08/09/2026, audit SEO final) : carte
    "summary_large_image" déclarée sans image dédiée — X/Twitter retombe en
    général sur og:image, mais ce n'est pas garanti. --}}
    @if ($seo['og_image'])
        <meta name="twitter:image" content="{{ $seo['og_image'] }}">
    @endif

    {{-- Organisation Schema.org — administrable via le module "Paramètres du site" (Filament\Pages\SiteSettings, brief §13).
         La clé JSON-LD doit être échappée en "@@context" (bug préexistant découvert en vérifiant le rendu réel :
         Blade compile toute occurrence non échappée, y compris en commentaire PHP, voir TECHNICAL_DOCUMENTATION.md §13). --}}
    <script type="application/ld+json">
    {!! json_encode(array_filter([
        '@@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteSettings->site_name,
        'url' => url('/'),
        'logo' => $siteSettings->logo_url,
        'description' => $siteSettings->description,
        'email' => $siteSettings->email,
        'telephone' => $siteSettings->phone,
        'sameAs' => $siteSettings->socialLinks() ?: null,
    ])) !!}
    </script>

    {{-- WebSite + SearchAction (08/09/2026, audit SEO final) — manquant avant
    ce correctif, condition pour l'éligibilité à la "sitelinks search box"
    dans les résultats Google. Aucune recherche unifiée sur tout le site
    n'existe (chaque section a son propre `?q=`) : l'annuaire est pris comme
    cible représentative (plus gros volume de contenu, ~3000 fiches). --}}
    <script type="application/ld+json">
    {!! json_encode([
        '@@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteSettings->site_name,
        'url' => url('/'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => url('/annuaire').'?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ]) !!}
    </script>

    {{-- SEO/Analytics globaux (Filament\Pages\SiteSettings) — chargés seulement si renseignés. --}}
    @if ($siteSettings->google_site_verification)
        <meta name="google-site-verification" content="{{ $siteSettings->google_site_verification }}">
    @endif
    @if ($siteSettings->google_analytics_id)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $siteSettings->google_analytics_id }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ $siteSettings->google_analytics_id }}');
        </script>
    @endif

    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white font-sans text-ink-800 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
        Aller au contenu principal
    </a>

    <x-site.header />

    @if (session('status'))
        <div class="mx-auto mt-4 max-w-4xl rounded-xl bg-green-50 px-4 py-3 text-sm font-medium text-green-800 sm:mx-auto" role="status">
            {{ session('status') }}
        </div>
    @endif

    <main id="main">
        {{ $slot }}
    </main>

    <x-site.footer />
</body>
</html>
