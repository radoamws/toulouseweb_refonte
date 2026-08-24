<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\ResolvesImageUrl;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Actualité (remplace t_news). Workflow de modération repris du legacy
 * (draft/pending/published/archived au lieu des codes D/F/S/T, voir
 * TECHNICAL_DOCUMENTATION.md §2.1/§9).
 */
class News extends Model
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, ResolvesImageUrl;

    protected $fillable = [
        'category_id', 'author_id', 'title', 'slug', 'excerpt', 'body', 'image',
        'status', 'published_at', 'legacy_id',
    ];

    protected $casts = ['published_at' => 'datetime'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->image));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NewsCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(NewsComment::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
