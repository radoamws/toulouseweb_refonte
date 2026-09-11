{{-- Voir resources/views/vendor/pagination/tailwind.blade.php pour le pourquoi de cette personnalisation. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex gap-2 items-center justify-between">

        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center px-4 py-2 text-sm font-medium text-ink-400 bg-white border border-ink-200 cursor-not-allowed leading-5 rounded-md">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-4 py-2 text-sm font-medium text-ink-700 bg-white border border-ink-200 leading-5 rounded-md hover:bg-brand-50 hover:text-brand-700 focus:outline-none focus:ring ring-brand-200 transition ease-in-out duration-150">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-4 py-2 text-sm font-medium text-ink-700 bg-white border border-ink-200 leading-5 rounded-md hover:bg-brand-50 hover:text-brand-700 focus:outline-none focus:ring ring-brand-200 transition ease-in-out duration-150">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex items-center px-4 py-2 text-sm font-medium text-ink-400 bg-white border border-ink-200 cursor-not-allowed leading-5 rounded-md">
                {!! __('pagination.next') !!}
            </span>
        @endif

    </nav>
@endif
