<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventCategory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agenda / événements (brief §6). Le menu THÉÂTRE n'est pas une entité
 * séparée : c'est ce même contrôleur filtré sur la catégorie "theatre"
 * (voir bySlug ci-dessous), avec sa propre URL comme demandé.
 */
class EventController extends Controller
{
    public function index(Request $request, ?EventCategory $category = null): View
    {
        return $this->renderIndex($request, $category);
    }

    /**
     * Une seule route `/agenda/{slug}` sert à la fois les catégories
     * (ex: /agenda/theatre) et les fiches événement — résolution par ordre
     * de priorité (catégorie d'abord, comme dans la navigation principale).
     */
    public function bySlug(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        if ($category = EventCategory::where('slug', $slug)->first()) {
            return $this->renderIndex($request, $category);
        }

        $event = Event::where('slug', $slug)->first();
        if (! $event) {
            return $this->redirectOrAbort($request->path());
        }

        return $this->show($event);
    }

    protected function renderIndex(Request $request, ?EventCategory $category): View
    {
        $date = $request->date('date');

        $events = Event::query()
            ->published()
            ->with(['area', 'categories'])
            ->when($category, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->where('event_categories.id', $category->id)))
            ->when($date, fn ($q) => $q->whereDate('start_date', '<=', $date)->where(function ($q2) use ($date) {
                $q2->whereDate('end_date', '>=', $date)->orWhereNull('end_date');
            }))
            ->when(! $date, fn ($q) => $q->upcoming())
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->orderBy('start_date')
            ->paginate(24)
            ->withQueryString();

        $categories = EventCategory::orderBy('order')->orderBy('name')->get();

        $seo = $category?->resolveSeo() ?? [];

        $view = $request->string('view')->value() === 'calendar' ? 'calendar' : 'list';
        $calendarMonth = null;
        $calendarCounts = [];

        if ($view === 'calendar') {
            $calendarMonth = $this->resolveCalendarMonth($request, $date);
            $calendarCounts = $this->countEventsByDay($calendarMonth, $category);
        }

        return view('agenda.index', compact(
            'events', 'categories', 'category', 'date', 'seo',
            'view', 'calendarMonth', 'calendarCounts'
        ));
    }

    protected function resolveCalendarMonth(Request $request, ?Carbon $date): Carbon
    {
        if ($request->filled('month')) {
            try {
                return Carbon::createFromFormat('Y-m', $request->string('month')->value())->startOfMonth();
            } catch (\Throwable) {
                // valeur de mois invalide dans l'URL — on retombe sur le mois courant plutôt que planter
            }
        }

        return ($date ?? now())->copy()->startOfMonth();
    }

    /**
     * Compte les événements par jour de début dans le mois (approximation
     * volontaire pour la vue calendrier : un événement s'étalant sur
     * plusieurs jours n'apparaît que sur son jour de début, pas sur toute sa
     * durée — sinon un festival d'une semaine "remplirait" toute la vue.
     * Le filtre par jour précis (`?date=`, vue liste) reste, lui, exact.
     *
     * @return array<string, int> clé "Y-m-d" => nombre d'événements
     */
    protected function countEventsByDay(Carbon $month, ?EventCategory $category): array
    {
        return Event::query()
            ->published()
            ->whereBetween('start_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->when($category, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->where('event_categories.id', $category->id)))
            ->selectRaw('DATE(start_date) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();
    }

    public function show(Event $event): View
    {
        abort_unless(in_array($event->status, ['published', 'expired'], true), 404);

        $event->load(['area', 'categories']);

        return view('agenda.show', [
            'event' => $event,
            'seo' => $event->resolveSeo(),
        ]);
    }
}
