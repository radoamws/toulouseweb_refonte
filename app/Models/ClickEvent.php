<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Événement de clic générique (remplace/étend t_stat_counter legacy — voir
 * TECHNICAL_DOCUMENTATION.md §2.3). Alimenté par
 * App\Services\Stats\ClickTrackingService via POST /track-click, appelable
 * depuis n'importe quel composant (bannière, catégorie, encadré, film...).
 */
class ClickEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'entity_type', 'entity_id', 'context', 'url', 'referrer', 'user_agent',
        'ip_hash', 'session_hash', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
