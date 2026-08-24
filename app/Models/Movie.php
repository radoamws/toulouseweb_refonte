<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\ResolvesImageUrl;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Fiche film (remplace t_cine_film). */
class Movie extends Model
{
    use HasSlug, HasSeoMeta, Trackable, ResolvesImageUrl;

    protected $fillable = [
        'title', 'slug', 'director', 'cast', 'genres', 'duration_minutes', 'synopsis',
        'poster', 'distributor', 'release_date', 'external_ref', 'legacy_id',
    ];

    protected $casts = ['release_date' => 'date'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug');
    }

    protected function posterUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->poster));
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MovieComment::class);
    }
}
