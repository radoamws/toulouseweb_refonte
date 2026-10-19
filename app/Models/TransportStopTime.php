<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Horaire par arrêt de la journée représentative (brief roadmap "contenu",
 * 16/10/2026). Voir App\Console\Commands\ImportTisseoSchedule — table la
 * plus volumineuse du projet (~236 000 lignes/semaine mesurées en réel),
 * régénérée entièrement à chaque import, pas d'historique (`timestamps`
 * désactivé, voir docblock de la migration).
 */
class TransportStopTime extends Model
{
    public $timestamps = false;

    protected $fillable = ['transport_trip_id', 'transport_stop_id', 'stop_sequence', 'departure_time'];

    public function trip(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function stop(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'transport_stop_id');
    }
}
