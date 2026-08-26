<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\ResolvesImageUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Catégorie de l'agenda. Le slug 'theatre' est celui exploité par la page
 * THÉÂTRE du menu principal (filtre agenda dédié, voir brief §6).
 */
class EventCategory extends Model
{
    use HasSlug, HasSeoMeta, ResolvesImageUrl;

    protected $fillable = ['name', 'slug', 'color', 'icon', 'order', 'legacy_id'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    protected function iconUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->icon));
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_category');
    }
}
