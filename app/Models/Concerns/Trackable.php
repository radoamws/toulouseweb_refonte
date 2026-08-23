<?php

namespace App\Models\Concerns;

use App\Models\ClickEvent;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Marque un modèle comme trackable par le système de clics générique
 * (remplace/étend t_stat_counter legacy — voir TECHNICAL_DOCUMENTATION.md §2.3).
 * L'entity_type stocké est le nom de table du modèle (stable, indépendant du
 * namespace PHP).
 */
trait Trackable
{
    public function clickEvents(): MorphMany
    {
        return $this->morphMany(ClickEvent::class, 'entity', 'entity_type', 'entity_id')
            ->where('entity_type', $this->getTable());
    }

    public function clickTrackingType(): string
    {
        return $this->getTable();
    }
}
