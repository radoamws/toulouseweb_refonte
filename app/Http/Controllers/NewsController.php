<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\Page;
use App\Support\AdminNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Actualités. Même pattern de résolution que l'agenda (brief §6 appliqué
 * par cohérence) : une URL /actualites/{slug} sert catégorie ou article.
 */
class NewsController extends Controller
{
    /**
     * Choix de tri de `/actualites` (demande client, 12/09/2026) — par
     * défaut, publication la plus récente d'abord (déjà le comportement
     * historique). "Date de l'événement" trie sur `start_date` (voir
     * App\Models\News, brief "informations pratiques" du 03/09/2026) — les
     * articles sans date d'événement (la majorité, simples actus) sont
     * toujours relégués en fin de liste quel que soit le sens choisi (voir
     * `orderByRaw('start_date IS NULL')` dans renderIndex()), jamais
     * mélangés arbitrairement avec des dates réelles.
     *
     * @var array<string, string>
     */
    public const SORT_OPTIONS = [
        'published_desc' => 'Publication — plus récente d\'abord',
        'published_asc' => 'Publication — plus ancienne d\'abord',
        'event_asc' => 'Date de l\'événement — croissante',
        'event_desc' => 'Date de l\'événement — décroissante',
    ];

    public function index(Request $request, ?NewsCategory $category = null): View
    {
        return $this->renderIndex($request, $category);
    }

    public function bySlug(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        if ($category = NewsCategory::where('slug', $slug)->first()) {
            return $this->renderIndex($request, $category);
        }

        $news = News::published()->where('slug', $slug)->first();
        if (! $news) {
            return $this->redirectOrAbort($request->path());
        }

        return $this->show($news);
    }

    protected function renderIndex(Request $request, ?NewsCategory $category): View
    {
        $requestedSort = $request->get('sort');
        $sort = is_string($requestedSort) && array_key_exists($requestedSort, self::SORT_OPTIONS) ? $requestedSort : 'published_desc';

        $news = News::query()
            ->published()
            ->with('category')
            ->when($category, fn ($q) => $q->where('category_id', $category->id))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when(in_array($sort, ['event_asc', 'event_desc'], true), fn ($q) => $q->orderByRaw('start_date IS NULL'))
            ->orderBy(
                str_starts_with($sort, 'event_')
                    // `COALESCE` : un article publié sans `published_at` renseigné
                    // ne doit pas être trié comme s'il datait de l'origine des temps
                    // (NULL = plus petite valeur possible en ASC) — voir même
                    // remarque sur HomeController::index().
                    ? 'start_date'
                    : DB::raw('COALESCE(published_at, created_at)'),
                str_ends_with($sort, '_asc') ? 'asc' : 'desc',
            )
            // Départage stable en cas d'égalité (ex. plusieurs articles sans
            // start_date, ou publiés à la même seconde) — sans ce
            // départage, l'ordre relatif de ces lignes n'est pas garanti
            // d'une requête à l'autre, ce qui casserait la pagination
            // (une fiche pourrait apparaître deux fois ou jamais entre 2 pages).
            ->orderBy('id', str_ends_with($sort, '_asc') ? 'asc' : 'desc')
            ->paginate(12)
            ->withQueryString();

        $categories = NewsCategory::orderBy('name')->get();

        return view('actualites.index', [
            'news' => $news,
            'categories' => $categories,
            'category' => $category,
            'sort' => $sort,
            'sortOptions' => self::SORT_OPTIONS,
            // Fiche "menu" migrée en repli (bug réel corrigé le 08/09/2026,
            // voir docblock équivalent sur EventController::renderIndex()) —
            // contrairement à agenda/annonces/cinema, aucun `t_seo_entity`
            // legacy "actualités" n'a jamais existé (vérifié) : repli final
            // sur un titre/description écrits ici, pour ne jamais laisser
            // /actualites sur le générique du layout. Un admin peut à tout
            // moment créer une Page `seo-menu-actualites` (PageResource) pour
            // reprendre la main sans toucher au code.
            'seo' => $category
                ? $category->resolveSeo()
                : (Page::where('key', 'seo-menu-actualites')->first()?->resolveSeo() ?? [
                    'title' => 'Actualités de Toulouse et sa région | ToulouseWeb',
                    'description' => "Toute l'actualité locale de Toulouse et sa région : événements, vie associative, culture, bons plans et informations pratiques.",
                ]),
        ]);
    }

    public function show(News $news): View
    {
        abort_unless($news->status === 'published', 404);

        $news->load(['category', 'comments' => fn ($q) => $q->where('status', 'published')->latest()]);

        $related = News::published()
            ->when($news->category_id, fn ($q) => $q->where('category_id', $news->category_id))
            ->where('id', '!=', $news->id)
            ->latest('published_at')
            ->limit(3)
            ->get();

        return view('actualites.show', [
            'news' => $news,
            'related' => $related,
            'seo' => $news->resolveSeo(),
        ]);
    }

    public function create(): View
    {
        $categories = NewsCategory::orderBy('name')->get();

        return view('actualites.create', ['categories' => $categories, 'seo' => []]);
    }

    /**
     * Dépôt public d'une proposition d'actualité (demande client,
     * 09/09/2026 — voir TECHNICAL_DOCUMENTATION.md §28). Même modèle de
     * modération STRICTE et non contournable que les autres dépôts publics
     * (Classified/Event/Listing) : `status` est TOUJOURS forcé à `pending`
     * ici, jamais de valeur envoyée par le visiteur.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:news_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'submitter_email' => ['required', 'email', 'max:255'],
            // Honeypot anti-spam (brief §18) : champ invisible, un vrai
            // visiteur ne le remplit jamais.
            'website' => ['size:0'],
        ]);

        // Le slug est généré automatiquement depuis `title` par HasSlug
        // (voir App\Models\News) — pas besoin de le fournir ici.
        $news = News::create([
            ...collect($validated)->except(['website'])->all(),
            'status' => 'pending', // jamais autre chose ici, voir docblock de la méthode
        ]);

        // Notification admin (demande client, voir App\Support\AdminNotifier
        // et TECHNICAL_DOCUMENTATION.md §28).
        AdminNotifier::send(
            'Nouvelle actualité à valider',
            [
                'Titre' => $news->title,
                'Catégorie' => $news->category?->name ?? '',
                'Proposée par' => $news->submitter_email,
            ],
            route('filament.admin.resources.news.edit', $news),
            'Valider ou refuser cette actualité',
        );

        return redirect()
            ->route('actualites.index')
            ->with('status', 'Votre proposition a bien été reçue et sera publiée après validation par notre équipe.');
    }
}
