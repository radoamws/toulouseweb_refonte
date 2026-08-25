<?php

namespace App\Http\Controllers;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Annonces (brief §8). Workflow de modération STRICT et non contournable :
 * `store()` force toujours `status = 'pending'`, jamais de valeur envoyée
 * par le visiteur — seul l'admin (ClassifiedResource, actions Valider/
 * Refuser) fait passer une annonce à `published`. Voir aussi
 * App\Models\Classified et l'audit sécurité §4 (ne jamais reproduire la
 * confiance aveugle du legacy envers les entrées utilisateur).
 */
class ClassifiedController extends Controller
{
    public function index(Request $request, ?ClassifiedCategory $category = null): View
    {
        return $this->renderIndex($request, $category);
    }

    public function bySlug(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        if ($category = ClassifiedCategory::where('slug', $slug)->first()) {
            return $this->renderIndex($request, $category);
        }

        $classified = Classified::where('status', 'published')->where('slug', $slug)->first();
        if (! $classified) {
            return $this->redirectOrAbort($request->path());
        }

        return $this->show($classified);
    }

    protected function renderIndex(Request $request, ?ClassifiedCategory $category): View
    {
        $classifieds = Classified::query()
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->with('category')
            ->when($category, fn ($q) => $q->where('category_id', $category->id))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('is_featured')
            ->latest()
            ->paginate(24)
            ->withQueryString();

        $categories = ClassifiedCategory::where('is_active', true)->orderBy('name')->get();

        return view('annonces.index', [
            'classifieds' => $classifieds,
            'categories' => $categories,
            'category' => $category,
            'seo' => $category?->resolveSeo() ?? [],
        ]);
    }

    public function show(Classified $classified): View
    {
        abort_unless($classified->status === 'published', 404);

        $classified->load(['category', 'media']);

        // Maillage interne (brief §13, SEO/GEO) — même catégorie, hors
        // annonce courante.
        $related = Classified::where('status', 'published')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where('category_id', $classified->category_id)
            ->where('id', '!=', $classified->id)
            ->latest()
            ->limit(4)
            ->get();

        return view('annonces.show', [
            'classified' => $classified,
            'related' => $related,
            'seo' => $classified->resolveSeo(),
        ]);
    }

    public function create(): View
    {
        $categories = ClassifiedCategory::where('is_active', true)->orderBy('name')->get();

        return view('annonces.create', ['categories' => $categories, 'seo' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:classified_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            // Honeypot anti-spam (brief §18) : champ invisible, un vrai
            // visiteur ne le remplit jamais.
            'website' => ['size:0'],
        ]);

        // Le slug est généré automatiquement depuis `title` par HasSlug
        // (voir App\Models\Classified) — pas besoin de le fournir ici.
        $classified = Classified::create([
            ...collect($validated)->except(['website'])->all(),
            'status' => 'pending', // jamais autre chose ici, voir docblock de la classe
        ]);

        return redirect()
            ->route('annonces.index')
            ->with('status', 'Votre annonce a bien été reçue et sera publiée après validation par notre équipe.');
    }
}
