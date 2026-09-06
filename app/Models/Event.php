<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Contracts\HasGoogleIndexingUrl;
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
class Event extends Model implements HasCloudflarePurgeUrls, HasGoogleIndexingUrl
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

    /**
     * Toujours inclure la home (demande client, TECHNICAL_DOCUMENTATION.md
     * §17) : `HomeController` affiche les 4 prochains événements publiés,
     * donc n'importe quel ajout/modif/suppression peut changer ce qui s'y
     * affiche — y compris depuis `scrape:events` (les URLs sont juste
     * accumulées en mémoire, pas un appel HTTP par événement scrapé, voir
     * docblock de App\Observers\CloudflarePurgeObserver).
     */
    public function cloudflarePurgeUrls(): array
    {
        // `categories()->get()` (requête fraîche), PAS `$this->categories` (la
        // collection potentiellement déjà chargée EN MÉMOIRE, mise en cache
        // sur CETTE instance dès le premier accès — un `attach()`/`sync()`
        // sur la relation pivot ne l'invalide pas automatiquement, elle
        // resterait "vide" si accédée une première fois avant l'attache).
        return array_filter(array_merge(
            [route('home'), route('agenda.index'), route('agenda.bySlug', $this->slug)],
            $this->categories()->get()->map(fn (EventCategory $c) => route('agenda.bySlug', $c->slug))->all(),
        ));
    }

    public function publicUrl(): string
    {
        return route('agenda.bySlug', $this->slug);
    }

    /** `EventController::show()` autorise aussi 'expired' (page accessible, juste hors listing) — voir son docblock. */
    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, ['published', 'expired'], true);
    }
}
