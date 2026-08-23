<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
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
class Listing extends Model implements HasMedia
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

    public function isRestaurant(): bool
    {
        return $this->cuisine_type !== null || $this->categories->contains(
            fn (Category $category) => $category->slug === 'restaurants'
        );
    }
}
