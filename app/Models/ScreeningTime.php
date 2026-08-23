<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horaire de séance réel (remplace t_cine_proj_heures). */
class ScreeningTime extends Model
{
    protected $fillable = ['screening_id', 'day', 'time', 'booking_url', 'legacy_id'];

    protected $casts = ['day' => 'date'];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }
}
