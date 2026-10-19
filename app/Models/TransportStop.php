<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Arrêt réel Tisséo (brief roadmap "contenu", 16/10/2026). Voir
 * App\Console\Commands\ImportTisseoSchedule — régénérée entièrement à
 * chaque import.
 */
class TransportStop extends Model
{
    protected $fillable = ['gtfs_stop_id', 'name', 'lat', 'lon'];
}
