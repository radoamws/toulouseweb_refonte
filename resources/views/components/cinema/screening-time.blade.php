@props(['screening', 'time'])

@php
    // Désactive le lien si aucune occurrence future de ce jour de la semaine
    // ne reste possible dans la fenêtre de la séance (demande client,
    // 22/09/2026 : "désactive les liens sur les horaires des dates passées
    // car les liens externes affichent une page expirée"). `$time->weekday`
    // est un jour RÉCURRENT (0=dimanche...6=samedi, pas une date calendaire,
    // voir App\Models\ScreeningTime) : on calcule donc la PROCHAINE
    // occurrence de ce jour à partir d'aujourd'hui, et on ne désactive que
    // si cette prochaine occurrence tombe APRÈS la fin de la séance — un
    // horaire déjà passé CETTE semaine mais qui revient la semaine
    // prochaine (toujours dans la fenêtre) reste actif.
    // Convention Carbon 0=dimanche...6=samedi, reprise du legacy — voir
    // docblock d'App\Models\ScreeningTime et le bug de sens inverse déjà
    // corrigé une fois sur cette même donnée (01/09/2026).
    $weekdays = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    $today = \Illuminate\Support\Carbon::today();
    $weekday = (int) $time->weekday;
    $nextOccurrence = $today->dayOfWeek === $weekday ? $today->copy() : $today->copy()->next($weekday);
    $isPast = $screening->end_date !== null && $nextOccurrence->greaterThan($screening->end_date);
    $label = $weekdays[$weekday] ?? '';
@endphp

@if ($time->booking_url && ! $isPast)
    <a
        href="{{ $time->booking_url }}"
        target="_blank"
        rel="noopener"
        data-track="screening_time:{{ $time->id }}:cinema_booking_click"
        title="Réserver — {{ $label }}"
        class="rounded-lg bg-ink-50 px-2 py-1 text-ink-700 underline decoration-dotted underline-offset-2 transition hover:bg-brand-50 hover:text-brand-700"
    >{{ $label }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}</a>
@else
    <span
        class="rounded-lg bg-ink-50 px-2 py-1 text-ink-400"
        title="{{ $isPast ? 'Séance passée' : $label }}"
    >{{ $label }} {{ \Illuminate\Support\Str::of($time->time)->limit(5, '') }}</span>
@endif
