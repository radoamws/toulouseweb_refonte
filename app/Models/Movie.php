<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\ResolvesImageUrl;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Fiche film (remplace t_cine_film). */
class Movie extends Model implements HasCloudflarePurgeUrls
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

    /**
     * Toujours inclure la home (demande client, TECHNICAL_DOCUMENTATION.md
     * §17) — voir docblock de News::cloudflarePurgeUrls(). Déclenché aussi
     * par `scrape:cinema` (AllocineDriver::upsertMovie() sauvegarde chaque
     * film rencontré) : sans découplage, un scraping quotidien de ~24 salles
     * déclencherait des centaines d'appels HTTP à l'API Cloudflare — voir
     * docblock de App\Observers\CloudflarePurgeObserver pour le mécanisme
     * qui évite ça (accumulation en mémoire, un seul lot d'appels en fin de
     * commande).
     */
    public function cloudflarePurgeUrls(): array
    {
        return array_filter([route('home'), route('cinema.index'), route('cinema.movie', $this->slug)]);
    }
}
