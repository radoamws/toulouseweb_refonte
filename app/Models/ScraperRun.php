<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal d'exécution d'un scraper (voir brief §7/§21). */
class ScraperRun extends Model
{
    protected $fillable = [
        'source_id', 'started_at', 'finished_at', 'status', 'items_found',
        'items_created', 'items_updated', 'items_skipped', 'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScraperSource::class, 'source_id');
    }
}
