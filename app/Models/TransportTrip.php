<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trajet réel Tisséo de la journée représentative (brief roadmap "contenu",
 * 16/10/2026). Voir App\Console\Commands\ImportTisseoSchedule — régénérée
 * entièrement à chaque import. `gtfs_route_id` n'est volontairement PAS une
 * clé étrangère vers TransportLine (voir docblock de la migration).
 */
class TransportTrip extends Model
{
    protected $fillable = ['gtfs_trip_id', 'gtfs_route_id'];

    public function stopTimes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TransportStopTime::class, 'transport_trip_id');
    }
}
