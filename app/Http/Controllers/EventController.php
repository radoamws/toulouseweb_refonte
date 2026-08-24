<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventCategory;
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

        return view('agenda.index', compact('events', 'categories', 'category', 'date', 'seo'));
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
