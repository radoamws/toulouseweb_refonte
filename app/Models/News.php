<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
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
 *
 * `start_date`/`end_date` (demande client, 03/09/2026) décrivent la fenêtre
 * de validité de l'ÉVÉNEMENT que l'article décrit (une brocante, un salon...)
 * — à ne pas confondre avec `published_at`, qui est la date de mise en ligne
 * de l'ARTICLE. Ce sont des colonnes `date` pures (pas d'heure), voir
 * `scopePublished()` pour la comparaison sûre (piège documenté dans la
 * migration `add_event_fields_to_news_table` et dans
 * `CinemaController::currentlyValid()`).
 */
class News extends Model implements HasCloudflarePurgeUrls
{
    use HasSlug, SoftDeletes, HasSeoMeta, Trackable, ResolvesImageUrl;

    protected $fillable = [
        'category_id', 'author_id', 'title', 'slug', 'excerpt', 'body', 'image',
        'status', 'published_at', 'legacy_id',
        'start_date', 'end_date', 'schedule', 'address', 'price', 'phone', 'email', 'website', 'youtube_url',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->image));
    }

    /** "01 sept. 2026" ou "01 sept. 2026 → 15 sept. 2026" ; null si pas de start_date (article sans événement). */
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
     * Convertit une URL YouTube (watch?v=, youtu.be/, /embed/, /shorts/,
     * éventuels paramètres additionnels) en URL `/embed/{id}` intégrable en
     * `<iframe>`. Retourne null si l'identifiant n'a pas pu être extrait
     * (lien mal formé saisi en admin) plutôt que de planter l'affichage.
     */
    protected function youtubeEmbedUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->youtube_url) {
                return null;
            }

            if (! preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_-]{11})/', $this->youtube_url, $matches)) {
                return null;
            }

            return 'https://www.youtube.com/embed/'.$matches[1];
        });
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

    /**
     * Demande client (03/09/2026) : un article dont l'événement décrit est
     * terminé (`end_date` dépassée) ne doit plus apparaître sur le front,
     * même si son statut reste "published" en admin. Comparaison en chaîne
     * `Y-m-d` nue (`now()->toDateString()`), PAS `now()` ni un objet Carbon
     * complet — `end_date` est une colonne `date` pure (voir docblock de
     * la classe et de la migration `add_event_fields_to_news_table`) ;
     * comparer à un datetime complet créerait le même bug que celui trouvé
     * et corrigé sur `CinemaController::currentlyValid()`.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()));
    }

    /**
     * Toujours inclure la home (demande client, TECHNICAL_DOCUMENTATION.md
     * §17) : `HomeController` affiche les 4 dernières actualités publiées,
     * donc n'importe quel ajout/modif/suppression peut changer ce qui s'y
     * affiche, pas seulement l'article concerné.
     */
    public function cloudflarePurgeUrls(): array
    {
        return array_filter([
            route('home'),
            route('actualites.index'),
            route('actualites.bySlug', $this->slug),
            $this->category ? route('actualites.bySlug', $this->category->slug) : null,
        ]);
    }
}
