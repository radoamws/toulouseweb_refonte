{{-- Icône illustrative par mode de transport (voir x-site.transport-carousel). --}}
@props(['type'])

@switch($type)
    @case('tram')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2v4" />
            <rect x="4" y="6" width="16" height="11" rx="3" />
            <path d="M7.5 10h3M13.5 10h3" />
            <circle cx="8" cy="19.5" r="1.5" />
            <circle cx="16" cy="19.5" r="1.5" />
        </svg>
        @break
    @case('teleo')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 4.5h18" />
            <path d="M12 4.5V8" />
            <rect x="7" y="8" width="10" height="8.5" rx="2.5" />
            <circle cx="12" cy="12.25" r="1.5" />
        </svg>
        @break
    @case('bus')
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="6.5" width="18" height="10.5" rx="2.5" />
            <path d="M6 11h12M6 6.5v4M18 6.5v4" />
            <circle cx="7.5" cy="19.5" r="1.5" />
            <circle cx="16.5" cy="19.5" r="1.5" />
        </svg>
        @break
    @default {{-- metro --}}
        <svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4" y="5.5" width="16" height="12" rx="4" />
            <path d="M7.5 10h3M13.5 10h3" />
            <circle cx="8" cy="19.5" r="1.5" />
            <circle cx="16" cy="19.5" r="1.5" />
        </svg>
@endswitch
