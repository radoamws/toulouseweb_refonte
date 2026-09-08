<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Association salle/film/langue (remplace t_cine_projection). */
class Screening extends Model
{
    protected $fillable = ['cinema_id', 'movie_id', 'language_id', 'start_date', 'end_date', 'preview', 'staff_pick', 'legacy_id'];

    // Format explicite 'Y-m-d' requis : le cast 'date' seul stocke en
    // 'Y-m-d H:i:s' (le composant heure est ignoré à l'affichage mais bien
    // écrit en base), ce qui fait échouer un `updateOrCreate` matchant sur
    // une chaîne 'Y-m-d' nue (cas réel rencontré par AllocineDriver — sur
    // SQLite l'absence de coercition de type fait alors créer un doublon
    // au lieu de mettre à jour ; MySQL tronque silencieusement la colonne
    // DATE donc le bug n'y était pas visible, mais le fix reste correct
    // indépendamment du moteur).
    protected $casts = ['preview' => 'boolean', 'staff_pick' => 'boolean', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];

    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function times(): HasMany
    {
        return $this->hasMany(ScreeningTime::class);
    }

    public function types(): BelongsToMany
    {
        return $this->belongsToMany(ScreeningType::class, 'screening_screening_type');
    }

    /**
     * Extrait de `CinemaController::currentlyValid()` (07/09/2026, audit
     * SEO/perf final) — cette logique était DUPLIQUÉE (avec régression :
     * voir ci-dessous) dans `GenerateSitemap` pour filtrer les films ayant
     * une séance en cours. Centralisée ici pour qu'un seul endroit porte le
     * correctif du bug déjà trouvé et documenté sur `CinemaController` :
     * `start_date`/`end_date` sont des colonnes `DATE` pures — comparer à
     * une chaîne `Y-m-d` nue (`now()->toDateString()`), JAMAIS à `now()`/un
     * objet Carbon complet (MySQL coerce silencieusement, SQLite compare en
     * texte brut et échoue — voir TECHNICAL_DOCUMENTATION.md §13).
     *
     * ⚠️ Bug réel trouvé et corrigé ici : `GenerateSitemap::handle()`
     * dupliquait cette même logique mais comparait à `now()` (objet Carbon
     * complet), pas encore corrigé lors du fix initial sur
     * `CinemaController` (fait ailleurs, jamais reporté ici) — le sitemap
     * pouvait donc omettre des films ayant pourtant des séances réellement
     * en cours, dès la première seconde après minuit, exactement le même
     * bug que celui déjà corrigé sur le front.
     */
    public function scopeCurrentlyValid(Builder|Relation $query): Builder|Relation
    {
        $today = now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today));
    }
}
