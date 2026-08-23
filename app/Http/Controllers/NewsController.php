<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsCategory;
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

    public function bySlug(Request $request, string $slug): View
    {
        if ($category = NewsCategory::where('slug', $slug)->first()) {
            return $this->renderIndex($request, $category);
        }

        $news = News::published()->where('slug', $slug)->firstOrFail();

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
            'seo' => $category?->resolveSeo() ?? [],
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
