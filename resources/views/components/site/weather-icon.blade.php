{{-- Icône météo réutilisable (voir x-site.weather-widget) — extraite pour
être utilisée 2 fois sur le même widget (icône réelle + grande icône
décorative en fond), sans dupliquer le SVG. --}}
@props(['icon'])

@switch($icon)
    @case('clear')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4.5" />
            <path d="M12 2.5v2.5M12 19v2.5M4.22 4.22l1.77 1.77M18 18l1.78 1.78M2.5 12H5M19 12h2.5M4.22 19.78L6 18M18 6l1.78-1.78" />
        </svg>
        @break
    @case('fog')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 8h9a3.5 3.5 0 100-7 4.5 4.5 0 00-8.5 1.5" />
            <path d="M4 12h16M3 16h16M6 20h13" />
        </svg>
        @break
    @case('rain')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 12.5a4 4 0 01.5-7.97A5.5 5.5 0 0117.5 6a4 4 0 01.8 7.92" />
            <path d="M8 16l-1 3M12 16l-1 3M16 16l-1 3" />
        </svg>
        @break
    @case('snow')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 12.5a4 4 0 01.5-7.97A5.5 5.5 0 0117.5 6a4 4 0 01.8 7.92" />
            <path d="M8 16v3M8 17.5l-1.5 1M8 17.5l1.5 1M12 16v3M12 17.5l-1.5 1M12 17.5l1.5 1M16 16v3M16 17.5l-1.5 1M16 17.5l1.5 1" />
        </svg>
        @break
    @case('storm')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 11.5a4 4 0 01.5-7.97A5.5 5.5 0 0117.5 5a4 4 0 01.8 7.92" />
            <path d="M13 13l-3 5h3l-2 4" />
        </svg>
        @break
    @default
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 12.5a4 4 0 01.5-7.97A5.5 5.5 0 0117.5 6a4 4 0 01.8 7.92" />
        </svg>
@endswitch
