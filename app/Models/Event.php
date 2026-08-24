<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\ResolvesImageUrl;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Événement d'agenda (remplace t_agendas ; corrige la FK area_id cassée du legacy). */
class Event extends Model
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, ResolvesImageUrl;

    protected $fillable = [
        'area_id', 'title', 'slug', 'subtitle', 'description', 'image', 'price',
        'start_date', 'end_date', 'schedule', 'booking_url', 'status', 'source',
        'external_ref', 'legacy_id',
    ];

    protected $casts = [
        'schedule' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->image));
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(EventCategory::class, 'event_category');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('end_date', '>=', now())
                ->orWhere(function (Builder $q2) {
                    $q2->whereNull('end_date')->where('start_date', '>=', now()->startOfDay());
                });
        });
    }

    public function isTheatre(): bool
    {
        return $this->categories->contains(fn (EventCategory $c) => $c->slug === 'theatre');
    }
}
