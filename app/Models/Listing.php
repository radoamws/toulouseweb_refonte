<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Contracts\HasGoogleIndexingUrl;
use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use App\Services\Migration\LegacyCleaner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Throwable;

/**
 * Fiche annuaire (payante ou gratuite). Remplace t_article. Les fiches
 * gratuites n'exposent volontairement que titre/adresse/téléphone côté
 * public (voir brief §5)... SAUF pendant leur essai gratuit temporaire
 * (demande client, 24/11/2026, voir TRIAL_MONTHS/hasPremiumFeatures()
 * ci-dessous et TECHNICAL_DOCUMENTATION.md §83) : la richesse des champs
 * ci-dessous s'applique au tier 'paid' ET à toute fiche encore dans sa
 * fenêtre d'essai, jamais aux fiches gratuites dont l'essai est expiré.
 */
class Listing extends Model implements HasMedia, HasCloudflarePurgeUrls, HasGoogleIndexingUrl
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, InteractsWithMedia;

    /**
     * Durée de l'essai gratuit (demande client, 24/11/2026, voir
     * TECHNICAL_DOCUMENTATION.md §83) : toute NOUVELLE fiche, quelle que
     * soit la formule choisie, profite des options payantes (photo,
     * description, galerie, site web, réservation) pendant 3 mois sans
     * avoir payé — passé ce délai, ces options disparaissent automatiquement
     * (voir `hasPremiumFeatures()`) sauf passage effectif à `tier = 'paid'`.
     */
    public const TRIAL_MONTHS = 3;

    protected $fillable = [
        'title', 'slug', 'tier', 'status', 'short_description', 'description',
        'address', 'city', 'postal_code', 'lat', 'lng', 'phone', 'email', 'website',
        'opening_hours', 'social_links', 'reservation_url', 'click_collect_url',
        'cuisine_type', 'published_at', 'legacy_id',
        'submitter_email', 'edit_token', 'pending_changes', 'editor_id',
        // Stripe/essai gratuit (voir TRIAL_MONTHS) — jamais dans les règles
        // de validation des contrôleurs publics (ListingController), donc
        // jamais atteignables depuis une requête visiteur malgré leur
        // présence ici ; mass-assignables uniquement pour que
        // ListingResource (admin) et StripePaymentService puissent les
        // écrire simplement.
        'premium_until', 'paid_at', 'stripe_checkout_session_id', 'trial_reminder_sent_at',
        'renewal_reminder_sent_at', 'stripe_last_processed_session_id',
    ];

    protected $casts = [
        'opening_hours' => 'array',
        'social_links' => 'array',
        'published_at' => 'datetime',
        'pending_changes' => 'array',
        'premium_until' => 'datetime',
        'paid_at' => 'datetime',
        'trial_reminder_sent_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $listing) {
            // Espace client (voir TECHNICAL_DOCUMENTATION.md) — uniquement
            // pour les fiches créées depuis le dépôt public à partir de
            // maintenant (submitter_email renseigné) : les ~2978 fiches
            // historiques migrées du legacy n'en ont pas, voir docblock de
            // la migration.
            if (! $listing->edit_token && $listing->submitter_email) {
                $listing->edit_token = Str::random(48);
            }

            // Essai gratuit (voir TRIAL_MONTHS ci-dessus) — jamais écrasé si
            // déjà fixé explicitement (ex. migration de données), et
            // jamais appliqué rétroactivement aux fiches historiques (cette
            // logique ne s'exécute qu'à la CRÉATION).
            if (! $listing->premium_until) {
                $listing->premium_until = now()->addMonths(self::TRIAL_MONTHS);
            }
        });

        static::saving(function (self $listing) {
            // `paid_at` renseigné automatiquement dès que `tier` passe à
            // 'paid' — que ce soit via le webhook Stripe
            // (StripePaymentService::markListingAsPaid()) ou manuellement
            // par un admin (ListingResource) : source unique de vérité,
            // jamais recalculé si déjà présent (ne pas écraser la vraie
            // date de paiement initiale lors d'une modification ultérieure).
            //
            // Rectification client (25/11/2026) : la fiche payante est
            // acquise pour 1 an, pas définitivement — `premium_until` est
            // donc aussi fixé à `now()->addYear()` ici au premier passage à
            // 'paid' (y compris quand la fiche était encore en plein essai
            // gratuit : le premier paiement repart d'une période payante
            // pleine et propre, il n'y a pas de "reste d'essai" à conserver),
            // sauf si un admin a lui-même explicitement renseigné une autre
            // valeur dans le même formulaire (`isDirty('premium_until')`,
            // ex. geste commercial sur une durée différente). Les VRAIS
            // renouvellements (qui doivent prolonger à partir de l'échéance
            // actuelle, pas la remplacer) passent exclusivement par
            // StripePaymentService::markListingAsPaid(), jamais par ce hook
            // qui ne s'exécute qu'une seule fois (`! $listing->paid_at`).
            if ($listing->isDirty('tier') && $listing->tier === 'paid' && ! $listing->paid_at) {
                $listing->paid_at = now();

                if (! $listing->isDirty('premium_until')) {
                    $listing->premium_until = now()->addYear();
                }
            }
        });
    }

    /**
     * Espace client : applique une modification en attente et l'efface,
     * sans jamais toucher au statut de publication.
     *
     * `_pending_category_id` (relation many-to-many) et `_pending_logo_path`
     * (collection média `logo`, pas une colonne) — même principe que
     * Event::applyPendingChanges()/Classified::applyPendingChanges().
     */
    public function applyPendingChanges(): void
    {
        if (! $this->pending_changes) {
            return;
        }

        $changes = $this->pending_changes;
        $categoryId = Arr::pull($changes, '_pending_category_id');
        $logoPath = Arr::pull($changes, '_pending_logo_path');

        $this->update([...$changes, 'pending_changes' => null]);

        if ($categoryId) {
            $this->categories()->sync([$categoryId]);
        }

        if ($logoPath && file_exists($logoPath)) {
            try {
                $this->addMedia($logoPath)->toMediaCollection('logo');
            } catch (Throwable $e) {
                Log::warning('Logo en attente (espace éditeur) rejeté à l\'application', ['exception' => $e->getMessage()]);
            }
        }
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug');
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (08/09/2026, audit SEO final,
     * TECHNICAL_DOCUMENTATION.md §24) : `t_article` (legacy) ne distinguait
     * pas toujours proprement téléphone/email/horaires dans un seul champ
     * texte libre — confirmé en base : 163/2978 fiches (5,5 %) ont un
     * `phone` du type `"Tel: 06 07 50 52 98<br>Mail: info@..."`, rendu tel
     * quel dans le lien `tel:`, le texte visible ET le JSON-LD
     * `LocalBusiness.telephone` (fait échouer la validation Google pour ces
     * fiches). `phone` reste INCHANGÉ en base (l'admin doit pouvoir voir/
     * corriger la vraie valeur legacy) — cet accesseur calcule une version
     * nettoyée pour tout affichage public, sans jamais modifier la colonne.
     *
     * Best-effort, pas une garantie à 100% sur des données aussi
     * hétérogènes : coupe à la première balise `<br>` ou au premier
     * marqueur "email/mail/courriel" rencontré (le plus souvent LA source
     * du mélange), puis retire tout préfixe non numérique en tête (labels
     * du type "Tel:"/"Journée:"/"Tél. :"). Retourne `null` plutôt qu'une
     * valeur fausse quand rien d'exploitable ne subsiste (ex. un champ qui
     * ne contenait qu'un email, jamais un numéro).
     */
    protected function cleanPhone(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->phone) {
                return null;
            }

            $value = preg_split('/<br\s*\/?>/i', $this->phone)[0];
            $value = preg_split('/\b(e-?mail|courriel|mail)\s*:?/i', $value)[0];
            $value = preg_replace('/^[^\d+]*/u', '', trim($value));
            $value = trim($value, " \t\n\r\0\x0B-:");

            // Filet de sécurité (02/10/2026, constaté en base sur 1 fiche :
            // "<b>Tel :</b> 06.38.67.97.62 - <b>Email :</b> ...") : le
            // marqueur "Email" coupé par le preg_split ci-dessus peut laisser
            // une balise ouvrante orpheline en fin de chaîne (ex. "<b>"),
            // que trim() seul (liste de caractères fixe) ne retire pas.
            // `LegacyCleaner::stripHtml()`, pas `strip_tags()` brut : ce
            // dernier dévore tout jusqu'au PROCHAIN ">" dès qu'il croise un
            // "<" isolé sans balise valide (piège PHP documenté sur son
            // propre docblock).
            $value = LegacyCleaner::stripHtml($value);
            $value = trim((string) $value, " \t\n\r\0\x0B-:");

            return $value !== '' ? $value : null;
        });
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (02/10/2026, signalé par le client,
     * exemple réel : "<b>Un ingénieur à la maison</b><br>6 Avenue de la
     * Gloire - Toulouse") : `address` legacy embarque parfois le nom de
     * l'enseigne en gras suivi d'un saut de ligne avant la vraie adresse —
     * confirmé en base sur 659/2978 fiches. Affiché tel quel via `{{ }}`
     * (échappement Blade), les balises apparaissaient LITTÉRALEMENT en
     * texte visible côté public. `address` reste INCHANGÉ en base (même
     * principe que `cleanPhone()` ci-dessus — l'admin voit toujours la
     * vraie valeur legacy dans le BO) — voir LegacyCleaner::stripHtml().
     */
    protected function cleanAddress(): Attribute
    {
        return Attribute::get(fn () => LegacyCleaner::stripHtml($this->address));
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (02/10/2026, signalé par le client :
     * lien "Visiter le site" pointant vers
     * "toulouseweb.com/annuaire/fiche/www.exemple.fr" au lieu du vrai site
     * externe) : `website`/`reservation_url` legacy sont parfois stockés
     * SANS schéma ("www.exemple.fr", confirmé en production) — un
     * `<a href="www.exemple.fr">` est alors interprété par le navigateur
     * comme un chemin RELATIF à la page courante, pas une URL externe.
     * `website`/`reservation_url` restent INCHANGÉS en base (même principe
     * que `cleanPhone()` ci-dessus) — ces accesseurs ajoutent `https://` à
     * l'affichage quand aucun schéma n'est présent, voir
     * LegacyCleaner::normalizeUrl().
     */
    protected function cleanWebsite(): Attribute
    {
        return Attribute::get(fn () => LegacyCleaner::normalizeUrl($this->website));
    }

    protected function cleanReservationUrl(): Attribute
    {
        return Attribute::get(fn () => LegacyCleaner::normalizeUrl($this->reservation_url));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('gallery');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'listing_category');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'listing_amenity');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    /**
     * Fiche payante dont le règlement est ACTUELLEMENT actif (pas seulement
     * déjà réglée un jour) — rectification client, 25/11/2026 : la fiche
     * payante est acquise pour 1 an, pas définitivement (voir
     * StripePaymentService::markListingAsPaid() et
     * TECHNICAL_DOCUMENTATION.md §83). Redevient faux dès que `premium_until`
     * est dépassé et non renouvelé, même si `tier` reste 'paid' en base
     * (`tier` garde la trace qu'il s'agissait d'un payeur, pour distinguer
     * côté admin une fiche "jamais payée" d'une fiche "payeur dont
     * l'abonnement a expiré").
     *
     * Utilisé là où l'avantage DOIT rester strictement réservé à un paiement
     * réel et actif, jamais à l'essai gratuit (badge "Partenaire", mise en
     * avant homepage `HomeController::featuredListings`, tri
     * `orderByDesc('tier')` de ListingController::index()) — ces avantages
     * disparaissent donc aussi si l'abonnement n'est pas renouvelé, ce qui
     * est la vraie incitation au renouvellement.
     */
    public function isPaid(): bool
    {
        return $this->tier === 'paid' && $this->premium_until && $this->premium_until->isFuture();
    }

    /**
     * Fiche payante, mais dont l'abonnement annuel est arrivé à échéance
     * sans renouvellement — distingue, côté espace client, "jamais payé" de
     * "payeur à relancer" (voir edit-space.blade.php).
     */
    public function isLapsedPaid(): bool
    {
        return $this->tier === 'paid' && ! $this->isPaid();
    }

    /**
     * Vrai si la fiche profite actuellement des options "payantes" (photo,
     * description, galerie, site web, réservation), qu'il s'agisse de
     * l'essai gratuit ou d'un abonnement payant actif — `premium_until` est
     * la SEULE source de vérité ici depuis la rectification du 25/11/2026
     * (un paiement n'étant plus définitif, `tier = 'paid'` seul ne suffit
     * plus à garantir ces options, voir `isPaid()` ci-dessus). Sans jamais
     * supprimer les données (`$listing->description` etc. restent en base) —
     * un (re)paiement les fait réapparaître instantanément.
     */
    public function hasPremiumFeatures(): bool
    {
        return (bool) ($this->premium_until && $this->premium_until->isFuture());
    }

    /** Essai gratuit en cours (ni expiré, ni déjà payé) — utilisé pour le compte à rebours affiché dans l'espace client. */
    public function isTrialing(): bool
    {
        return $this->tier !== 'paid' && $this->premium_until && $this->premium_until->isFuture();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function isRestaurant(): bool
    {
        return $this->cuisine_type !== null || $this->categories->contains(
            fn (Category $category) => $category->slug === 'restaurants'
        );
    }

    /**
     * Toujours inclure la home (demande client, TECHNICAL_DOCUMENTATION.md
     * §17) : simplification volontaire plutôt que de ne l'inclure que si
     * `tier === 'paid'` (seules les fiches payantes publiées y apparaissent,
     * voir HomeController) — couvre aussi le cas d'un passage gratuit ↔
     * payant sans logique supplémentaire, au prix d'une purge de la home un
     * peu plus fréquente que strictement nécessaire (négligeable, une URL
     * de plus dans le même lot).
     */
    public function cloudflarePurgeUrls(): array
    {
        // `categories()->get()` (requête fraîche), pas `$this->categories` —
        // voir le commentaire équivalent sur Event::cloudflarePurgeUrls().
        return array_filter(array_merge(
            [route('home'), route('annuaire.index'), route('annuaire.show', $this->slug)],
            $this->categories()->get()->map(fn (Category $c) => route('annuaire.category', $c->slug))->all(),
        ));
    }

    public function publicUrl(): string
    {
        return route('annuaire.show', $this->slug);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === 'published';
    }
}
