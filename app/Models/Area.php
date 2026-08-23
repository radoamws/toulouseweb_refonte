<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Lieu physique où se déroulent des événements (remplace t_areas). */
class Area extends Model
{
    use HasSlug;

    protected $fillable = [
        'name', 'slug', 'address', 'city', 'postal_code', 'lat', 'lng', 'phone', 'website', 'legacy_id',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
