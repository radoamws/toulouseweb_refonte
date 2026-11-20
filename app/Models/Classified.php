<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Contracts\HasGoogleIndexingUrl;
use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Throwable;

/**
 * Petite annonce. Workflow strict imposé par le brief §8 : jamais de
 * publication automatique, toujours user -> pending -> validation admin ->
 * published (voir Http\Controllers\ClassifiedController et
 * Filament\Resources\ClassifiedResource).
 */
class Classified extends Model implements HasMedia, HasCloudflarePurgeUrls, HasGoogleIndexingUrl
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, InteractsWithMedia;

    protected $fillable = [
        'category_id', 'user_id', 'editor_id', 'title', 'slug', 'description', 'price', 'location',
        'contact_phone', 'contact_email', 'submitter_email', 'edit_token', 'pending_changes',
        'status', 'is_featured', 'expires_at',
        'moderated_by', 'moderated_at', 'rejection_reason', 'legacy_id',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'expires_at' => 'datetime',
        'moderated_at' => 'datetime',
        'pending_changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $classified) {
            // Même pattern que NewsletterSubscriber::unsubscribe_token —
            // token opaque généré une fois, jamais régénéré ensuite.
            if (! $classified->edit_token) {
                $classified->edit_token = Str::random(48);
            }
        });
    }

    /**
     * Espace client (TECHNICAL_DOCUMENTATION.md) : applique une modification
     * en attente et l'efface, sans jamais toucher au statut de publication.
     *
     * `_pending_photo_path` (espace éditeur, "Grande modification" du
     * 17/11/2026 — édition à champs complets) : chemin TEMPORAIRE d'une
     * nouvelle photo déposée via `EditorClassifiedController::update()`
     * (voir `ImageSanitizer::sanitizeToTempFile()`), jamais une vraie
     * colonne — retiré avant le `update()` générique ci-dessous, puis
     * ajouté à la collection média seulement ici, une fois la modification
     * validée par l'admin (jamais avant, même principe que le reste de
     * `pending_changes`).
     */
    public function applyPendingChanges(): void
    {
        if (! $this->pending_changes) {
            return;
        }

        $changes = $this->pending_changes;
        $photoPath = Arr::pull($changes, '_pending_photo_path');

        $this->update([...$changes, 'pending_changes' => null]);

        if ($photoPath && file_exists($photoPath)) {
            try {
                $this->addMedia($photoPath)->toMediaCollection('photos');
            } catch (Throwable $e) {
                Log::warning('Photo en attente (espace éditeur) rejetée à l\'application', ['exception' => $e->getMessage()]);
            }
        }
    }

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

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** Toujours inclure la home (demande client, TECHNICAL_DOCUMENTATION.md §17) — voir docblock de News::cloudflarePurgeUrls(). */
    public function cloudflarePurgeUrls(): array
    {
        return array_filter([
            route('home'),
            route('annonces.index'),
            route('annonces.bySlug', $this->slug),
            $this->category ? route('annonces.bySlug', $this->category->slug) : null,
        ]);
    }

    public function publicUrl(): string
    {
        return route('annonces.bySlug', $this->slug);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === 'published';
    }
}
