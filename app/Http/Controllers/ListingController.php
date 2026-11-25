<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Editor;
use App\Models\Listing;
use App\Models\Page;
use App\Rules\GenuineImage;
use App\Rules\Recaptcha;
use App\Services\Uploads\ImageSanitizer;
use App\Support\AdminNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Annuaire (brief §5) : listing par catégorie avec recherche, fiche
 * détaillée pour les fiches payantes. Les fiches gratuites n'exposent que
 * titre/adresse/téléphone côté vue (voir resources/views/annuaire/*).
 *
 * Résolution de slug volontairement manuelle (pas de route-model-binding
 * implicite) : sinon un slug legacy inconnu déclenche un 404 AVANT que le
 * contrôleur ne puisse consulter la table `redirects` (voir
 * Controller::redirectOrAbort).
 *
 * ⚠️ Recherche géographique (brief §5) — LIMITE CONNUE : la base legacy n'a
 * jamais stocké de coordonnées lat/lng structurées pour les fiches annuaire
 * (colonnes présentes dans le schéma migré mais vides à 100%, confirmé en
 * direct le 25/08/2026 côté `toulouseweb_old.t_article` — aucune colonne
 * géo n'existe côté source). Une vraie recherche "autour de moi"/par rayon
 * nécessiterait un géocodage de ~2 978 adresses via un service externe
 * (Google Maps, Nominatim/OSM...) — décision produit + éventuelles
 * identifiants d'API hors scope ici. En attendant, un filtre par VILLE est
 * implémenté (`?city=`), la ville étant extraite au mieux depuis le champ
 * adresse legacy en texte libre (`LegacyCleaner::postalAndCity()`,
 * `migrate:listings`) — ~39% de couverture (1155/2978), le reste des
 * adresses ne se terminant pas par un "code postal + ville" reconnaissable
 * (URLs, texte marketing, formats non-français...).
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

        // Sous-rubriques (demande client, 19/09/2026 : "il y a plusieurs
        // sous rubriques" — ex. Restaurants a "Restaurant spectacle",
        // "Guinguettes", "Pizzerias"... déjà en base, mais introuvables nulle
        // part dans le menu jusqu'ici, aucune page n'exposait les enfants
        // d'une catégorie). $topCategory = ancêtre de niveau 0, pour rester
        // actif dans la sidebar ET afficher les sous-rubriques même quand on
        // navigue déjà DANS une sous-rubrique (ex. sur "Restaurant spectacle",
        // on doit toujours voir les autres sous-rubriques de Restaurants).
        $topCategory = $category;
        while ($topCategory && $topCategory->level > 0) {
            $topCategory = $topCategory->parent;
        }
        $subCategories = $topCategory
            ? Category::where('parent_id', $topCategory->id)->where('is_active', true)->orderBy('order')->orderBy('name')->get()
            : collect();

        $listings = Listing::query()
            ->published()
            ->when($categoryIds, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->whereIn('categories.id', $categoryIds)))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            // Recherche géographique (brief §5) : filtre par ville — voir
            // docblock de classe pour la limite connue (pas de vraies
            // coordonnées lat/lng, la ville est extraite au mieux depuis
            // l'adresse legacy, ~39% de couverture).
            ->when($request->filled('city'), fn ($q) => $q->where('city', $request->string('city')))
            ->with('categories')
            // Payantes d'abord (brief §5), mais seulement tant que
            // l'abonnement est réellement actif (rectification client,
            // 25/11/2026 — l'abonnement payant est annuel, pas définitif,
            // voir Listing::isPaid()/TECHNICAL_DOCUMENTATION.md §83) : une
            // fiche payante expirée sans renouvellement ne doit plus
            // remonter devant les fiches gratuites.
            ->orderByRaw("CASE WHEN tier = 'paid' AND premium_until > ? THEN 0 ELSE 1 END ASC", [now()])
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();

        $topCategories = Category::where('level', 0)->where('is_active', true)->orderBy('order')->orderBy('name')->get();

        // Villes les plus représentées (annuaire complet, pas juste la page
        // affichée) — repli propre le temps qu'un vrai géocodage arrive.
        $cities = Listing::published()
            ->whereNotNull('city')
            ->select('city')
            ->groupBy('city')
            ->selectRaw('COUNT(*) as total')
            ->orderByDesc('total')
            ->limit(30)
            ->pluck('city');

        $seo = $category ? $category->resolveSeo() : (Page::where('key', 'seo-menu-annuaire')->first()?->resolveSeo() ?? []);

        $this->recordPageView($request, $category ? 'category' : null, $category?->id);

        return view('annuaire.index', compact('listings', 'topCategories', 'category', 'topCategory', 'subCategories', 'seo', 'cities'));
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

        $this->recordPageView($request, 'listing', $listing->id);

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
     * Dépôt public d'une fiche annuaire (brief §5). Choix gratuit/payant
     * laissé au visiteur (demande client, 09/09/2026 — inspiré du
     * formulaire legacy `/referencer-site`, voir TECHNICAL_DOCUMENTATION.md
     * §28 : gratuite = fiche simplifiée, payante = fiche complète). Mais
     * `status` reste TOUJOURS forcé à `pending` ici, quel que soit le tier
     * choisi — jamais de publication automatique, même pour une demande
     * payante (le legacy publiait par erreur immédiatement, bug documenté
     * et volontairement NON reproduit ici) : seul l'admin (ListingResource)
     * fait passer une fiche à `published`.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'tier' => ['required', 'in:free,paid'],
            'title' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            // Champs de la fiche "complète" (tier payant uniquement côté
            // vue, voir annuaire/create.blade.php) — toujours validés ici
            // même si non affichés pour une demande gratuite : un visiteur
            // qui les enverrait quand même (tier=free) n'a pas de raison
            // d'être bloqué, ils seront simplement enregistrés.
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'url', 'max:255'],
            'reservation_url' => ['nullable', 'url', 'max:255'],
            // Upload d'image sécurisé (demande client, 25/09/2026 : logo de
            // l'établissement pour la fiche payante) — même garde-fou que
            // ClassifiedController::store() (voir App\Rules\GenuineImage et
            // App\Services\Uploads\ImageSanitizer) : `image`/`mimes:...`
            // inspectent déjà le contenu réel (pas que l'extension déclarée),
            // GenuineImage ajoute un contrôle explicite supplémentaire.
            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:4096', new GenuineImage()],
            // Honeypot anti-spam (brief §18) : champ invisible, un vrai
            // visiteur ne le remplit jamais. Nommé différemment de
            // ClassifiedController/ContactController pour ne pas entrer en
            // collision avec le vrai champ `website` de Listing.
            'url_verification' => ['size:0'],
            'recaptcha_token' => Recaptcha::rules('annuaire'),
        ]);

        // Rattachement automatique (demande client, 17/11/2026) — voir
        // ClassifiedController::store(). `email` restant facultatif ici
        // (contrairement aux 3 autres entités), pas de compte à
        // trouver/créer si le déposant ne l'a pas renseigné.
        $editor = Auth::guard('editor')->user()
            ?? (! empty($validated['email']) ? Editor::findOrInviteByEmail($validated['email']) : null);

        $listing = Listing::create([
            ...collect($validated)->except(['category_id', 'logo', 'url_verification', 'recaptcha_token'])->all(),
            'status' => 'pending', // jamais autre chose ici, voir docblock de la méthode
            // Espace client (demande client, 16/11/2026 — voir
            // TECHNICAL_DOCUMENTATION.md, étape 3) : distinct de `email`
            // (champ métier affiché publiquement, modifiable ensuite par le
            // déposant) — nullable, un dépôt sans email n'aura simplement
            // pas d'espace client (voir Listing::booted()).
            'submitter_email' => $validated['email'] ?? null,
            'editor_id' => $editor?->id,
        ]);
        $listing->categories()->attach($validated['category_id']);

        if ($request->hasFile('logo')) {
            // Le logo ne doit jamais faire échouer la soumission elle-même
            // (la fiche est déjà enregistrée à ce stade) — un échec de
            // sanitisation est journalisé et la fiche reste simplement sans
            // logo, à ajouter par l'admin si besoin lors de la modération.
            try {
                $tempPath = ImageSanitizer::sanitizeToTempFile($request->file('logo'));
                $listing->addMedia($tempPath)->toMediaCollection('logo');
            } catch (Throwable $e) {
                Log::warning('Logo fiche annuaire rejeté après validation', ['exception' => $e->getMessage()]);
            }
        }

        // Notification admin (demande client, voir App\Support\AdminNotifier
        // et TECHNICAL_DOCUMENTATION.md §28).
        AdminNotifier::send(
            'Nouvelle fiche annuaire à valider',
            [
                'Titre' => $listing->title,
                'Formule' => $listing->tier === 'paid' ? 'Payante' : 'Gratuite',
                'Ville' => $listing->city ?? '',
                'Email' => $listing->email ?? '',
                'Téléphone' => $listing->phone ?? '',
            ],
            route('filament.admin.resources.listings.edit', $listing),
            'Valider ou refuser cette fiche',
        );

        // Comptes éditeur (demande client, 17/11/2026) — voir
        // ClassifiedController::store() et
        // Controller::notifySubmitterToManageTheirAccount(). Rien à
        // envoyer sans email (voir `$editor` ci-dessus) ni si déjà connecté.
        if ($editor && ! Auth::guard('editor')->check()) {
            $this->notifySubmitterToManageTheirAccount($editor, $listing->title, 'Votre fiche annuaire a bien été reçue');
        }

        // Paiement immédiat (demande client, 24/11/2026, voir
        // App\Http\Controllers\ListingPaymentController et
        // TECHNICAL_DOCUMENTATION.md §83) : "autoriser un choix de paiement
        // immédiat aussi" — un déposant ayant choisi la formule payante part
        // directement sur Stripe Checkout plutôt que d'attendre d'être
        // recontacté. Nécessite un `edit_token` (donc un email renseigné,
        // voir Listing::booted()) pour pouvoir revenir sur sa fiche ensuite
        // — sans email, ou tant que STRIPE_PAYMENTS_ENABLED=false, le
        // parcours reste inchangé (règlement manuel après validation).
        if ($listing->tier === 'paid' && config('services.stripe.enabled') && $listing->edit_token) {
            return redirect()->route('annuaire.listings.checkout', $listing->edit_token);
        }

        return redirect()
            ->route('annuaire.index')
            ->with('status', 'Votre fiche a bien été reçue et sera publiée après validation par notre équipe.');
    }

    /**
     * Espace client (demande client, 16/11/2026, voir
     * TECHNICAL_DOCUMENTATION.md) — même mécanisme que
     * ClassifiedController::editSpace()/updateEditSpace() (étape 1). Seules
     * les fiches créées depuis le dépôt public ont un `edit_token` (voir
     * Listing::booted()) — les fiches historiques migrées du legacy
     * n'en auront jamais, volontairement (pas d'email de déposant fiable).
     */
    public function editSpace(string $token): View
    {
        $listing = Listing::where('edit_token', $token)->firstOrFail();

        return view('annuaire.edit-space', ['listing' => $listing, 'seo' => []]);
    }

    public function updateEditSpace(Request $request, string $token): \Illuminate\Http\RedirectResponse
    {
        $listing = Listing::where('edit_token', $token)->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'url', 'max:255'],
            'reservation_url' => ['nullable', 'url', 'max:255'],
            'url_verification' => ['size:0'],
        ]);

        // N'écrit JAMAIS directement sur les colonnes publiées — voir
        // docblock de la migration d'espace client et Listing::applyPendingChanges().
        $listing->update([
            'pending_changes' => collect($validated)->except('url_verification')->all(),
        ]);

        AdminNotifier::send(
            'Modification de fiche annuaire en attente de validation',
            ['Titre actuel' => $listing->title, 'Déposant' => $listing->submitter_email ?? ''],
            route('filament.admin.resources.listings.edit', $listing),
            'Voir la modification proposée',
        );

        return redirect()
            ->route('annuaire.editSpace', $token)
            ->with('status', 'Votre modification a bien été enregistrée et sera visible après validation par notre équipe.');
    }

    /**
     * Espace éditeur authentifié ("Grande modification", demande client
     * 17/11/2026, point 4, puis précisé le même jour : *"tous les champs et
     * informations saisies précédemment. Sauf le mail"*) — mêmes principes
     * que ClassifiedController::editOwned()/updateOwned(). `tier`
     * (gratuite/payante) EST modifiable ici (revenu sur la limite initiale
     * "implication de facturation hors scope" : toute modification passe de
     * toute façon par la modération admin avant d'être appliquée, voir
     * Listing::applyPendingChanges() — même garde-fou qu'un changement de
     * catégorie ou de logo). Seul `email` est volontairement ABSENT :
     * identité du compte, jamais modifiable depuis un formulaire de contenu.
     *
     * `category_id` (relation many-to-many `categories()`) et `logo`
     * (collection média `InteractsWithMedia`, pas une colonne) suivent le
     * même principe de clé spéciale que
     * EventController::updateOwned()/ClassifiedController::updateOwned() —
     * voir Listing::applyPendingChanges().
     */
    public function editOwned(Listing $listing): View
    {
        abort_unless($listing->editor_id === Auth::guard('editor')->id(), 403);

        $categories = Category::where('is_active', true)->orderBy('level')->orderBy('order')->orderBy('name')->get();

        return view('editeur.annuaire.modifier', ['listing' => $listing, 'categories' => $categories, 'seo' => []]);
    }

    public function updateOwned(Request $request, Listing $listing): \Illuminate\Http\RedirectResponse
    {
        abort_unless($listing->editor_id === Auth::guard('editor')->id(), 403);

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'tier' => ['required', 'in:free,paid'],
            'title' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'url', 'max:255'],
            'reservation_url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:4096', new GenuineImage()],
            'url_verification' => ['size:0'],
        ]);

        $pendingChanges = collect($validated)->except(['url_verification', 'logo', 'category_id'])->all();
        $pendingChanges['_pending_category_id'] = $validated['category_id'];

        if ($request->hasFile('logo')) {
            try {
                $pendingChanges['_pending_logo_path'] = ImageSanitizer::sanitizeToTempFile($request->file('logo'));
            } catch (Throwable $e) {
                Log::warning('Logo en attente (espace éditeur) rejeté après validation', ['exception' => $e->getMessage()]);
            }
        }

        $listing->update(['pending_changes' => $pendingChanges]);

        AdminNotifier::send(
            'Modification de fiche annuaire en attente de validation',
            ['Titre actuel' => $listing->title, 'Déposant' => $listing->submitter_email ?? ''],
            route('filament.admin.resources.listings.edit', $listing),
            'Voir la modification proposée',
        );

        return redirect()
            ->route('editeur.mes-publications')
            ->with('status', 'Votre modification a bien été enregistrée et sera visible après validation par notre équipe.');
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
