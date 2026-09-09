<?php

namespace App\Http\Controllers;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Page;
use App\Rules\GenuineImage;
use App\Services\Uploads\ImageSanitizer;
use App\Support\AdminNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

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
            // Fiche "menu" migrée en repli (bug réel corrigé le 08/09/2026,
            // voir docblock équivalent sur EventController::renderIndex()).
            'seo' => $category ? $category->resolveSeo() : (Page::where('key', 'seo-menu-annonces')->first()?->resolveSeo() ?? []),
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
            // Upload d'image sécurisé (demande client, voir App\Rules\GenuineImage
            // et App\Services\Uploads\ImageSanitizer) : `image`/`mimes:...`
            // inspectent déjà le contenu réel (pas que l'extension déclarée),
            // GenuineImage ajoute un contrôle explicite supplémentaire.
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:4096', new GenuineImage()],
            // Honeypot anti-spam (brief §18) : champ invisible, un vrai
            // visiteur ne le remplit jamais.
            'website' => ['size:0'],
        ]);

        // Le slug est généré automatiquement depuis `title` par HasSlug
        // (voir App\Models\Classified) — pas besoin de le fournir ici.
        $classified = Classified::create([
            ...collect($validated)->except(['website', 'photo'])->all(),
            'status' => 'pending', // jamais autre chose ici, voir docblock de la classe
        ]);

        if ($request->hasFile('photo')) {
            // La photo ne doit jamais faire échouer la soumission elle-même
            // (la donnée est déjà enregistrée à ce stade) — un échec de
            // sanitisation est journalisé et l'annonce reste simplement sans
            // photo, à ajouter par l'admin si besoin lors de la modération.
            try {
                $tempPath = ImageSanitizer::sanitizeToTempFile($request->file('photo'));
                $classified->addMedia($tempPath)->toMediaCollection('photos');
            } catch (Throwable $e) {
                Log::warning('Photo annonce rejetée après validation', ['exception' => $e->getMessage()]);
            }
        }

        // Notification admin (demande client, voir App\Support\AdminNotifier
        // et TECHNICAL_DOCUMENTATION.md §28).
        AdminNotifier::send(
            'Nouvelle annonce à valider',
            [
                'Titre' => $classified->title,
                'Catégorie' => $classified->category?->name ?? '',
                'Email de contact' => $classified->contact_email,
                'Téléphone' => $classified->contact_phone ?? '',
            ],
            route('filament.admin.resources.classifieds.edit', $classified),
            'Valider ou refuser cette annonce',
        );

        return redirect()
            ->route('annonces.index')
            ->with('status', 'Votre annonce a bien été reçue et sera publiée après validation par notre équipe.');
    }
}
