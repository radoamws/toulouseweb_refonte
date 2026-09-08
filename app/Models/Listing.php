<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Contracts\HasGoogleIndexingUrl;
use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Fiche annuaire (payante ou gratuite). Remplace t_article. Les fiches
 * gratuites n'exposent volontairement que titre/adresse/téléphone côté
 * public (voir brief §5) — la richesse des champs ci-dessous ne s'applique
 * pleinement qu'au tier 'paid'.
 */
class Listing extends Model implements HasMedia, HasCloudflarePurgeUrls, HasGoogleIndexingUrl
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, InteractsWithMedia;

    protected $fillable = [
        'title', 'slug', 'tier', 'status', 'short_description', 'description',
        'address', 'city', 'postal_code', 'lat', 'lng', 'phone', 'email', 'website',
        'opening_hours', 'social_links', 'reservation_url', 'click_collect_url',
        'cuisine_type', 'published_at', 'legacy_id',
    ];

    protected $casts = [
        'opening_hours' => 'array',
        'social_links' => 'array',
        'published_at' => 'datetime',
    ];

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

            return $value !== '' ? $value : null;
        });
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

    public function isPaid(): bool
    {
        return $this->tier === 'paid';
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
