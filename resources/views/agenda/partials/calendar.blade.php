@php
    // Grille de 6 semaines (42 jours), du lundi précédant le 1er du mois au
    // dimanche suivant le dernier jour — convention calendrier français
    // (semaine commençant le lundi).
    $firstOfMonth = $calendarMonth->copy()->startOfMonth();
    $gridStart = $firstOfMonth->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
    $today = now()->startOfDay();
    $prevMonth = $calendarMonth->copy()->subMonthNoOverflow();
    $nextMonth = $calendarMonth->copy()->addMonthNoOverflow();
    $weekdays = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
@endphp
<div class="mt-6 rounded-2xl border border-ink-100 bg-white p-4 shadow-sm sm:p-6">
    <div class="flex items-center justify-between">
        <x-ui.button
            :href="request()->fullUrlWithQuery(['month' => $prevMonth->format('Y-m'), 'date' => null])"
            variant="ghost" size="sm"
        >&larr; {{ $prevMonth->translatedFormat('F Y') }}</x-ui.button>

        <h2 class="font-heading text-lg font-bold capitalize text-ink-900">{{ $calendarMonth->translatedFormat('F Y') }}</h2>

        <x-ui.button
            :href="request()->fullUrlWithQuery(['month' => $nextMonth->format('Y-m'), 'date' => null])"
            variant="ghost" size="sm"
        >{{ $nextMonth->translatedFormat('F Y') }} &rarr;</x-ui.button>
    </div>

    <div class="mt-4 grid grid-cols-7 gap-1 text-center text-xs font-semibold uppercase tracking-wide text-ink-400">
        @foreach ($weekdays as $label)
            <div class="py-1">{{ $label }}</div>
        @endforeach
    </div>

    <div class="mt-1 grid grid-cols-7 gap-1">
        @for ($i = 0; $i < 42; $i++)
            @php
                $day = $gridStart->copy()->addDays($i);
                $inMonth = $day->month === $calendarMonth->month;
                $count = $calendarCounts[$day->format('Y-m-d')] ?? 0;
                $isToday = $day->isSameDay($today);
                $isSelected = $date && $day->isSameDay($date);
            @endphp
            {{-- Pas de data-track ici : une case de calendrier n'est pas une
                 entité (entity_id doit être un entier, voir track-click.js /
                 ClickTrackingController) — un jour n'en est pas un, contrairement
                 à une bannière/catégorie/fiche/film réels. --}}
            <a
                href="{{ request()->fullUrlWithQuery(['date' => $day->format('Y-m-d'), 'view' => 'list']) }}"
                class="flex aspect-square flex-col items-center justify-center rounded-lg text-sm transition
                    {{ ! $inMonth ? 'text-ink-300' : 'text-ink-800' }}
                    {{ $isSelected ? 'bg-brand-600 text-white' : ($isToday ? 'border border-brand-400 font-semibold' : 'hover:bg-ink-50') }}"
            >
                <span>{{ $day->day }}</span>
                @if ($count > 0 && $inMonth)
                    <span class="mt-0.5 h-1.5 w-1.5 rounded-full {{ $isSelected ? 'bg-white' : 'bg-accent-500' }}" aria-hidden="true"></span>
                @endif
            </a>
        @endfor
    </div>

    <p class="mt-4 text-xs text-ink-400">
        Un point indique au moins un événement ce jour-là (jour de début). Cliquez sur un jour pour voir le détail.
    </p>
</div>
