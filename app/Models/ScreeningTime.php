<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Créneau hebdomadaire récurrent d'une séance (remplace t_cine_proj_heures).
 * `weekday` (0-6) reprend tel quel la convention de `t_cine_proj_heures.jour`
 * du legacy — ce n'est pas une date calendaire, voir Screening::start_date/
 * end_date pour la fenêtre de validité et TECHNICAL_DOCUMENTATION.md §10.
 */
class ScreeningTime extends Model
{
    protected $fillable = ['screening_id', 'weekday', 'time', 'booking_url', 'legacy_id'];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }
}
