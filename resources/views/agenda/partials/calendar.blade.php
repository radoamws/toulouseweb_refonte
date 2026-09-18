@php
    // Grille de 6 semaines (42 jours), du lundi précédant le 1er du mois au
    // dimanche suivant le dernier jour — convention calendrier français
    // (semaine commençant le lundi).
    $firstOfMonth = $calendarMonth->copy()->startOfMonth();
    $gridStart = $firstOfMonth->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
    $today = now()->startOfDay();
    $prevMonth = $calendarMonth->copy()->subMonthNoOverflow();
    $nextMonth = $calendarMonth->copy()->addMonthNoOverflow();
    $weekdays = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
@endphp
{{-- Calendrier compact, toujours affiché à côté de la liste (demande
client, 18/09/2026 : "le calendrier doit être sur la liste et en petit" —
plus de bascule liste/calendrier séparée, voir agenda/index.blade.php). --}}
<div class="rounded-2xl border border-ink-100 bg-white p-3 shadow-sm">
    <div class="flex items-center justify-between">
        <a
            href="{{ request()->fullUrlWithQuery(['month' => $prevMonth->format('Y-m'), 'date' => null]) }}"
            class="rounded-lg p-1 text-ink-400 hover:bg-ink-50 hover:text-ink-700"
            aria-label="Mois précédent"
        >
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 010 1.06L8.06 10l4.73 4.71a.75.75 0 11-1.06 1.06l-5.25-5.25a.75.75 0 010-1.06l5.25-5.25a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>
        </a>

        <h2 class="text-xs font-semibold capitalize text-ink-900">{{ $calendarMonth->translatedFormat('F Y') }}</h2>

        <a
            href="{{ request()->fullUrlWithQuery(['month' => $nextMonth->format('Y-m'), 'date' => null]) }}"
            class="rounded-lg p-1 text-ink-400 hover:bg-ink-50 hover:text-ink-700"
            aria-label="Mois suivant"
        >
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 010-1.06L11.94 10 7.21 5.29a.75.75 0 111.06-1.06l5.25 5.25a.75.75 0 010 1.06l-5.25 5.25a.75.75 0 01-1.06 0z" clip-rule="evenodd" /></svg>
        </a>
    </div>

    <div class="mt-2 grid grid-cols-7 gap-0.5 text-center text-[10px] font-semibold uppercase text-ink-400">
        @foreach ($weekdays as $label)
            <div>{{ $label }}</div>
        @endforeach
    </div>

    <div class="mt-0.5 grid grid-cols-7 gap-0.5">
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
                href="{{ request()->fullUrlWithQuery(['date' => $day->format('Y-m-d')]) }}"
                class="relative flex aspect-square flex-col items-center justify-center rounded-md text-[11px] transition
                    {{ ! $inMonth ? 'text-ink-300' : 'text-ink-700' }}
                    {{ $isSelected ? 'bg-brand-600 text-white' : ($isToday ? 'border border-brand-400 font-semibold' : 'hover:bg-ink-50') }}"
            >
                <span>{{ $day->day }}</span>
                @if ($count > 0 && $inMonth)
                    <span class="absolute bottom-0.5 h-1 w-1 rounded-full {{ $isSelected ? 'bg-white' : 'bg-accent-500' }}" aria-hidden="true"></span>
                @endif
            </a>
        @endfor
    </div>

    @if ($date)
        <a href="{{ request()->fullUrlWithQuery(['date' => null]) }}" class="mt-2 block text-center text-xs font-medium text-brand-700 hover:underline">
            Voir tous les jours
        </a>
    @endif
</div>
