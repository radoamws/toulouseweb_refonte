<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\ResolvesImageUrl;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Catégorie de l'annuaire (3 niveaux). Enfants/Sports/Mariages/Restaurants/
 * Spectacles/La Nuit du menu legacy sont des catégories de ce module, pas des
 * entités séparées (voir TECHNICAL_DOCUMENTATION.md §6).
 */
class Category extends Model
{
    use HasSlug, HasSeoMeta, Trackable, ResolvesImageUrl;

    protected $fillable = [
        'parent_id', 'name', 'slug', 'level', 'icon', 'description', 'order', 'is_active', 'legacy_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected function iconUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->icon));
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    protected static function booted(): void
    {
        // Le niveau (0/1/2) est dérivé automatiquement du parent plutôt que
        // saisi à la main, reprenant la règle métier legacy (CategoryController
        // getChildNiveauByParent) sans son SQL brut. Voir TECHNICAL_DOCUMENTATION.md §9.
        static::saving(function (self $category) {
            $category->level = $category->parent_id
                ? (self::find($category->parent_id)?->level ?? 0) + 1
                : 0;
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_category');
    }
}
