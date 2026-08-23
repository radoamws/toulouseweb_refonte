<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Annuaire (brief §5) : listing par catégorie avec recherche, fiche
 * détaillée pour les fiches payantes. Les fiches gratuites n'exposent que
 * titre/adresse/téléphone côté vue (voir resources/views/annuaire/*).
 */
class ListingController extends Controller
{
    public function index(Request $request, ?Category $category = null): View
    {
        $categoryIds = $category ? $this->categoryAndDescendantIds($category) : null;

        $listings = Listing::query()
            ->published()
            ->when($categoryIds, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->whereIn('categories.id', $categoryIds)))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->with('categories')
            ->orderByDesc('tier') // payantes d'abord (brief §5)
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();

        $topCategories = Category::where('level', 0)->where('is_active', true)->orderBy('order')->orderBy('name')->get();

        $seo = $category ? $category->resolveSeo() : (\App\Models\Page::where('key', 'seo-menu-annuaire')->first()?->resolveSeo() ?? []);

        return view('annuaire.index', compact('listings', 'topCategories', 'category', 'seo'));
    }

    public function show(Listing $listing): View
    {
        abort_unless($listing->status === 'published', 404);

        $listing->load(['categories', 'amenities', 'media']);

        $related = Listing::published()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $listing->categories->pluck('id')))
            ->where('id', '!=', $listing->id)
            ->limit(4)
            ->get();

        return view('annuaire.show', [
            'listing' => $listing,
            'related' => $related,
            'seo' => $listing->resolveSeo(),
        ]);
    }

    /** Inclut la catégorie elle-même + tous ses descendants (niveaux 1 et 2). */
    protected function categoryAndDescendantIds(Category $category): array
    {
        $ids = [$category->id];
        $children = Category::where('parent_id', $category->id)->pluck('id');
        foreach ($children as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, Category::where('parent_id', $childId)->pluck('id')->all());
        }

        return $ids;
    }
}
