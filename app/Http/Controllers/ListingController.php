<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Annuaire (brief §5) : listing par catégorie avec recherche, fiche
 * détaillée pour les fiches payantes. Les fiches gratuites n'exposent que
 * titre/adresse/téléphone côté vue (voir resources/views/annuaire/*).
 *
 * Résolution de slug volontairement manuelle (pas de route-model-binding
 * implicite) : sinon un slug legacy inconnu déclenche un 404 AVANT que le
 * contrôleur ne puisse consulter la table `redirects` (voir
 * Controller::redirectOrAbort).
 */
class ListingController extends Controller
{
    public function index(Request $request, ?string $categorySlug = null): View
    {
        $category = $categorySlug ? Category::where('slug', $categorySlug)->first() : null;
        if ($categorySlug && ! $category) {
            return $this->redirectOrAbort($request->path());
        }

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

        $seo = $category ? $category->resolveSeo() : (Page::where('key', 'seo-menu-annuaire')->first()?->resolveSeo() ?? []);

        return view('annuaire.index', compact('listings', 'topCategories', 'category', 'seo'));
    }

    public function show(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $listing = Listing::where('slug', $slug)->where('status', 'published')->first();

        if (! $listing) {
            return $this->redirectOrAbort($request->path());
        }

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

    public function create(): View
    {
        $categories = Category::where('is_active', true)->orderBy('level')->orderBy('order')->orderBy('name')->get();

        return view('annuaire.create', ['categories' => $categories, 'seo' => []]);
    }

    /**
     * Dépôt public d'une fiche annuaire (brief §5). Workflow de modération
     * STRICT et non contournable, sur le même modèle que
     * ClassifiedController::store() : `tier`/`status` sont TOUJOURS forcés
     * ici, jamais de valeur envoyée par le visiteur — seul l'admin
     * (ListingResource) fait passer une fiche en payante et/ou publiée.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            // Honeypot anti-spam (brief §18) : champ invisible, un vrai
            // visiteur ne le remplit jamais. Nommé différemment de
            // ClassifiedController/ContactController pour ne pas entrer en
            // collision avec le vrai champ `website` de Listing.
            'url_verification' => ['size:0'],
        ]);

        $listing = Listing::create([
            ...collect($validated)->except(['category_id', 'url_verification'])->all(),
            'tier' => 'free', // jamais autre chose ici — voir docblock de la méthode
            'status' => 'pending',
        ]);
        $listing->categories()->attach($validated['category_id']);

        return redirect()
            ->route('annuaire.index')
            ->with('status', 'Votre fiche a bien été reçue et sera publiée après validation par notre équipe.');
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
