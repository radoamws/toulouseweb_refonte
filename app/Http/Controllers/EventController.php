<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Page;
use App\Rules\GenuineImage;
use App\Services\Uploads\ImageSanitizer;
use App\Support\AdminNotifier;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

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

        // Fiche "menu" migrée (t_seo_entity) en repli quand aucune catégorie
        // n'est sélectionnée (bug réel trouvé le 08/09/2026, audit SEO final,
        // TECHNICAL_DOCUMENTATION.md §24 : /agenda servait le titre/description
        // générique du layout au lieu de ce contenu migré et réellement écrit
        // — même correctif que ListingController::index()).
        $seo = $category ? $category->resolveSeo() : (Page::where('key', 'seo-menu-agenda')->first()?->resolveSeo() ?? []);

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

        // Maillage interne (brief §13, SEO/GEO) — mêmes catégories, à venir,
        // hors événement courant.
        $related = Event::published()->upcoming()
            ->whereHas('categories', fn ($q) => $q->whereIn('event_categories.id', $event->categories->pluck('id')))
            ->where('id', '!=', $event->id)
            ->orderBy('start_date')
            ->limit(4)
            ->get();

        return view('agenda.show', [
            'event' => $event,
            'related' => $related,
            'seo' => $event->resolveSeo(),
        ]);
    }

    public function create(): View
    {
        $categories = EventCategory::orderBy('order')->orderBy('name')->get();

        return view('agenda.create', ['categories' => $categories, 'seo' => []]);
    }

    /**
     * Dépôt public d'un événement (brief §6, "proposition d'événement par le
     * public" — même modèle de modération STRICTE et non contournable que
     * ClassifiedController::store()/ListingController::store() : `status`
     * et `source` sont TOUJOURS forcés ici, jamais de valeur envoyée par le
     * visiteur. Seul l'admin (EventResource, déjà administrable avec le
     * statut "pending") fait passer un événement à `published`.
     *
     * Le lieu (`area_id`) n'est pas choisi dans une liste déroulante : la
     * table `areas` compte plusieurs milliers de lignes (import legacy brut,
     * voir TECHNICAL_DOCUMENTATION.md §13), impraticable en `<select>`. Le
     * visiteur tape simplement le nom du lieu ; on réutilise une `Area`
     * existante du même nom si elle existe, sinon on en crée une nouvelle
     * (elle aussi soumise à la même modération : l'événement reste `pending`
     * tant que l'admin ne l'a pas validé, qu'elle référence un lieu déjà
     * connu ou tout neuf).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'event_category_id' => ['required', 'exists:event_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'venue_name' => ['required', 'string', 'max:255'],
            'venue_address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'string', 'max:255'],
            'booking_url' => ['nullable', 'url', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Upload d'image sécurisé (demande client, voir App\Rules\GenuineImage
            // et App\Services\Uploads\ImageSanitizer) : `image`/`mimes:...`
            // inspectent déjà le contenu réel (pas que l'extension déclarée),
            // GenuineImage ajoute un contrôle explicite supplémentaire.
            'image' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:4096', new GenuineImage()],
            // Honeypot anti-spam (brief §18) : champ invisible, un vrai
            // visiteur ne le remplit jamais.
            'website' => ['size:0'],
        ]);

        $area = Area::firstOrCreate(
            ['name' => trim($validated['venue_name'])],
            ['address' => $validated['venue_address'] ?? null]
        );

        $event = Event::create([
            'area_id' => $area->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'] ?? null,
            'booking_url' => $validated['booking_url'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'status' => 'pending', // jamais autre chose ici, voir docblock de la méthode
            'source' => 'user_submitted',
        ]);
        $event->categories()->attach($validated['event_category_id']);

        if ($request->hasFile('image')) {
            // Même remarque que ClassifiedController::store() : ne doit
            // jamais faire échouer la soumission elle-même.
            try {
                $event->image = ImageSanitizer::sanitizeAndStore($request->file('image'), 'events');
                $event->save();
            } catch (Throwable $e) {
                Log::warning('Image événement rejetée après validation', ['exception' => $e->getMessage()]);
            }
        }

        // Notification admin (demande client, voir App\Support\AdminNotifier
        // et TECHNICAL_DOCUMENTATION.md §28).
        AdminNotifier::send(
            'Nouvel événement à valider',
            [
                'Titre' => $event->title,
                'Lieu' => $area->name,
                'Date de début' => $event->start_date->format('d/m/Y'),
            ],
            route('filament.admin.resources.events.edit', $event),
            "Valider ou refuser cet événement",
        );

        return redirect()
            ->route('agenda.index')
            ->with('status', 'Votre événement a bien été reçu et sera publié après validation par notre équipe.');
    }
}
