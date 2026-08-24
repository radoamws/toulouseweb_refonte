<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrl;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Bannière carrousel homepage/pages, administrable (brief §4) : ajout,
 * modification, suppression, réordonnancement, activation/désactivation.
 */
class Slider extends Model
{
    use Trackable, ResolvesImageUrl;

    protected $fillable = [
        'title', 'image', 'link_url', 'client_name', 'order', 'delay_ms',
        'starts_at', 'ends_at', 'is_active', 'legacy_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->image));
    }

    public function placements(): HasMany
    {
        return $this->hasMany(SliderPlacement::class);
    }

    public function scopeActiveOn(Builder $query, string $page): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('placements', fn (Builder $q) => $q->where('page', $page))
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('order');
    }
}
