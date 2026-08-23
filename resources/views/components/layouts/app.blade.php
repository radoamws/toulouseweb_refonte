@props([
    'seo' => [],
])

@php
    $seo = array_merge([
        'title' => 'ToulouseWeb — Toulouse et sa région',
        'description' => "Actualités, agenda, cinéma, annuaire, annonces et sorties à Toulouse et dans sa région.",
        'canonical_url' => url()->current(),
        'robots' => 'index,follow',
        'og_image' => asset('images/og-default.jpg'),
    ], array_filter($seo));
@endphp
<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical_url'] }}">

    {{-- Open Graph / Twitter Card — voir brief §13 --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ToulouseWeb">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical_url'] }}">
    @if ($seo['og_image'])
        <meta property="og:image" content="{{ $seo['og_image'] }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">

    {{-- Organisation Schema.org — TODO Phase 11 : rendre administrable via un module "Paramètres du site" --}}
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'ToulouseWeb',
        'url' => url('/'),
        'description' => "Portail local de Toulouse et sa région : actualités, agenda, cinéma, annuaire, annonces.",
    ]) !!}
    </script>

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
