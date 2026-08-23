<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Petite annonce. Workflow strict imposé par le brief §8 : jamais de
 * publication automatique, toujours user -> pending -> validation admin ->
 * published (voir Http\Controllers\ClassifiedController et
 * Filament\Resources\ClassifiedResource).
 */
class Classified extends Model implements HasMedia
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, InteractsWithMedia;

    protected $fillable = [
        'category_id', 'user_id', 'title', 'slug', 'description', 'price', 'location',
        'contact_phone', 'contact_email', 'status', 'is_featured', 'expires_at',
        'moderated_by', 'moderated_at', 'rejection_reason',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'expires_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClassifiedCategory::class, 'category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
