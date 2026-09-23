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
        'area_id', 'venue_name', 'venue_address', 'title', 'slug', 'subtitle', 'description', 'image', 'price',
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

    /**
     * "01 sept. 2026" ou "01 sept. 2026 → 15 sept. 2026" (demande client,
     * 18/09/2026 : afficher début ET fin sur chaque fiche de la liste
     * agenda — voir resources/views/agenda/index.blade.php). Même logique
     * que News::eventDateRange() (déjà utilisée manuellement, en inline,
     * dans agenda/show.blade.php) — centralisée ici pour la liste.
     */
    protected function eventDateRange(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->start_date) {
                return null;
            }

            $range = $this->start_date->translatedFormat('d M Y');

            if ($this->end_date && ! $this->end_date->isSameDay($this->start_date)) {
                $range .= ' → '.$this->end_date->translatedFormat('d M Y');
            }

            return $range;
        });
    }

    /**
     * Nom/adresse du lieu RÉEL de cet événement, avec repli sur ceux de
     * l'`Area` générique associée (demande client, 23/09/2026 : "l'adresse
     * de l'événement n'est pas l'adresse du 'Lieu'") — nécessaire pour les
     * agendas mutualisés (OpenAgenda, ex. "Toulouse Métropole") qui
     * agrègent des événements se déroulant chacun à un lieu physique
     * différent, tous rattachés au même `area_id` générique. Voir
     * AbstractOpenAgendaDriver, seul driver à renseigner `venue_name`/
     * `venue_address` pour l'instant (les autres salles n'ont qu'un seul
     * lieu physique réel, l'adresse de l'Area est déjà la bonne).
     */
    protected function venueDisplayName(): Attribute
    {
        return Attribute::get(fn () => $this->venue_name ?: $this->area?->name);
    }

    protected function venueDisplayAddress(): Attribute
    {
        return Attribute::get(fn () => $this->venue_address ?: $this->area?->address);
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

    /**
     * ⚠️ Bug réel trouvé et corrigé (23/09/2026, demande client : "n'affiche
     * pas les agendas dont la date du jour égale la date de l'événement ni
     * la date du jour est inclus entre la date de début et la date de
     * fin") — comparait `end_date` (souvent minuit, `2026-09-23 00:00:00`,
     * pour un événement scrapé/saisi sans horaire précis) à `now()` (un
     * VRAI timestamp complet, ex. `2026-09-23 14:32:07`) : un événement se
     * déroulant AUJOURD'HUI disparaissait de `/agenda` dès la première
     * seconde après minuit, avant même que la journée ne commence. Comparé
     * désormais en DATE pure (`whereDate`), même convention que le filtre
     * par jour du calendrier (`renderIndex()`) et que
     * `Screening::scopeCurrentlyValid()` (bug identique déjà corrigé côté
     * cinéma) : un événement reste "à venir" pour toute sa journée de fin,
     * quelle que soit l'heure actuelle.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where(function (Builder $q) use ($today) {
            $q->whereDate('end_date', '>=', $today)
                ->orWhere(function (Builder $q2) use ($today) {
                    $q2->whereNull('end_date')->whereDate('start_date', '>=', $today);
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
