<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Contracts\HasGoogleIndexingUrl;
use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Builder;
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
