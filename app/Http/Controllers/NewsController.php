<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Actualités. Même pattern de résolution que l'agenda (brief §6 appliqué
 * par cohérence) : une URL /actualites/{slug} sert catégorie ou article.
 */
class NewsController extends Controller
{
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
        $news = News::query()
            ->published()
            ->with('category')
            ->when($category, fn ($q) => $q->where('category_id', $category->id))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();

        $categories = NewsCategory::orderBy('name')->get();

        return view('actualites.index', [
            'news' => $news,
            'categories' => $categories,
            'category' => $category,
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
}
