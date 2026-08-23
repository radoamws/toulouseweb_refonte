<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Association salle/film/langue (remplace t_cine_projection). */
class Screening extends Model
{
    protected $fillable = ['cinema_id', 'movie_id', 'language_id', 'preview', 'staff_pick', 'legacy_id'];

    protected $casts = ['preview' => 'boolean', 'staff_pick' => 'boolean'];

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
