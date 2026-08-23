<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
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
    use HasSlug, HasSeoMeta;

    protected $fillable = ['name', 'slug', 'color', 'icon', 'order', 'legacy_id'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_category');
    }
}
