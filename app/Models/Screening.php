<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
