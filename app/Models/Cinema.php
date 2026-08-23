<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Salle de cinéma (remplace t_cine). */
class Cinema extends Model
{
    use HasSlug, HasSeoMeta, Trackable;

    protected $fillable = ['name', 'slug', 'address', 'lat', 'lng', 'external_url', 'is_active', 'legacy_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }
}
