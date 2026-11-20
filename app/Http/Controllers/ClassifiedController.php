<?php

namespace App\Http\Controllers;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Editor;
use App\Models\Page;
use App\Rules\GenuineImage;
use App\Rules\Recaptcha;
use App\Services\Uploads\ImageSanitizer;
use App\Support\AdminNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return $this->show($request, $classified);
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

        $this->recordPageView($request, $category ? 'classified_category' : null, $category?->id);

        return view('annonces.index', [
            'classifieds' => $classifieds,
            'categories' => $categories,
            'category' => $category,
            // Fiche "menu" migrée en repli (bug réel corrigé le 08/09/2026,
            // voir docblock équivalent sur EventController::renderIndex()).
            'seo' => $category ? $category->resolveSeo() : (Page::where('key', 'seo-menu-annonces')->first()?->resolveSeo() ?? []),
        ]);
    }

    public function show(Request $request, Classified $classified): View
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

        $this->recordPageView($request, 'classified', $classified->id);

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
            'recaptcha_token' => Recaptcha::rules('annonce'),
        ]);

        // Rattachement automatique (demande client, 17/11/2026) : un
        // dépôt anonyme obtient/retrouve son compte éditeur par email (pour
        // y recevoir le lien de création de compte plus bas) ; un dépôt
        // déjà authentifié est simplement rattaché à son compte existant.
        $editor = Auth::guard('editor')->user() ?? Editor::findOrInviteByEmail($validated['contact_email']);

        // Le slug est généré automatiquement depuis `title` par HasSlug
        // (voir App\Models\Classified) — pas besoin de le fournir ici.
        $classified = Classified::create([
            ...collect($validated)->except(['website', 'photo', 'recaptcha_token'])->all(),
            'status' => 'pending', // jamais autre chose ici, voir docblock de la classe
            // Capturé une fois, distinct de `contact_email` modifiable
            // ensuite par le déposant — voir docblock de la migration
            // d'espace client.
            'submitter_email' => $validated['contact_email'],
            'editor_id' => $editor->id,
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

        // Comptes éditeur (demande client, 17/11/2026 : "changer le mail...
        // avec le lien pour pouvoir créer son compte pour qu'il administre
        // toutes ses publications") — remplace l'ancien lien par jeton
        // propre à CETTE annonce (§74) par le lien de création de compte,
        // qui donnera accès à TOUTES ses publications une fois inscrit.
        // Rien à envoyer si déjà connecté : son tableau de bord suffit.
        if (! Auth::guard('editor')->check()) {
            $this->notifySubmitterToManageTheirAccount($editor, $classified->title, 'Votre annonce a bien été reçue');
        }

        return redirect()
            ->route('annonces.index')
            ->with('status', 'Votre annonce a bien été reçue et sera publiée après validation par notre équipe.');
    }

    /**
     * Espace client (demande client, 16/11/2026, réclamation Mme Alves de
     * Sousa — voir TECHNICAL_DOCUMENTATION.md) : accès par token opaque,
     * même pattern que NewsletterSubscriptionController::unsubscribe().
     * Pas de compte/mot de passe — le lien reçu par email EST l'accès.
     */
    public function editSpace(string $token): View
    {
        $classified = Classified::where('edit_token', $token)->firstOrFail();

        return view('annonces.edit-space', ['classified' => $classified, 'seo' => []]);
    }

    public function updateEditSpace(Request $request, string $token): RedirectResponse
    {
        $classified = Classified::where('edit_token', $token)->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            // Honeypot anti-spam, même convention que les autres formulaires publics.
            'website' => ['size:0'],
        ]);

        // N'écrit JAMAIS directement sur les colonnes publiées : une
        // annonce déjà en ligne reste affichée telle quelle tant que
        // l'équipe n'a pas validé la modification (voir docblock de la
        // migration et Classified::applyPendingChanges()).
        $classified->update([
            'pending_changes' => collect($validated)->except('website')->all(),
        ]);

        AdminNotifier::send(
            'Modification d\'annonce en attente de validation',
            ['Titre actuel' => $classified->title, 'Déposant' => $classified->submitter_email ?? ''],
            route('filament.admin.resources.classifieds.edit', $classified),
            'Voir la modification proposée',
        );

        return redirect()
            ->route('annonces.editSpace', $token)
            ->with('status', 'Votre modification a bien été enregistrée et sera visible après validation par notre équipe.');
    }

    /**
     * Espace éditeur authentifié ("Grande modification", demande client
     * 17/11/2026, point 4 : *"chaque entité en cours de modification doit
     * avoir tous les champs disponible que lors de la création"*) — par
     * opposition à `editSpace()`/`updateEditSpace()` ci-dessus (lien par
     * jeton, champs limités, étape antérieure toujours fonctionnelle pour
     * les liens déjà envoyés). Ownership vérifiée via `editor_id`, jamais
     * via le jeton ici — voir `routes/web.php` (middleware `auth:editor`).
     */
    public function editOwned(Classified $classified): View
    {
        abort_unless($classified->editor_id === Auth::guard('editor')->id(), 403);

        $categories = ClassifiedCategory::where('is_active', true)->orderBy('name')->get();

        return view('editeur.annonces.modifier', ['classified' => $classified, 'categories' => $categories, 'seo' => []]);
    }

    public function updateOwned(Request $request, Classified $classified): RedirectResponse
    {
        abort_unless($classified->editor_id === Auth::guard('editor')->id(), 403);

        // `contact_email` volontairement ABSENT (demande client, 17/11/2026 :
        // "tous les champs ... sauf le mail") — contrairement à
        // `updateEditSpace()` ci-dessus (lien par jeton, sans compte), l'email
        // n'est plus modifiable depuis l'espace éditeur authentifié.
        $validated = $request->validate([
            'category_id' => ['required', 'exists:classified_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:4096', new GenuineImage()],
            'website' => ['size:0'],
        ]);

        $pendingChanges = collect($validated)->except(['website', 'photo'])->all();

        if ($request->hasFile('photo')) {
            try {
                // Chemin TEMPORAIRE seulement — jamais attaché à la
                // collection média tant que l'admin n'a pas validé, voir
                // Classified::applyPendingChanges().
                $pendingChanges['_pending_photo_path'] = ImageSanitizer::sanitizeToTempFile($request->file('photo'));
            } catch (Throwable $e) {
                Log::warning('Photo en attente (espace éditeur) rejetée après validation', ['exception' => $e->getMessage()]);
            }
        }

        $classified->update(['pending_changes' => $pendingChanges]);

        AdminNotifier::send(
            'Modification d\'annonce en attente de validation',
            ['Titre actuel' => $classified->title, 'Déposant' => $classified->submitter_email ?? ''],
            route('filament.admin.resources.classifieds.edit', $classified),
            'Voir la modification proposée',
        );

        return redirect()
            ->route('editeur.mes-publications')
            ->with('status', 'Votre modification a bien été enregistrée et sera visible après validation par notre équipe.');
    }
}
