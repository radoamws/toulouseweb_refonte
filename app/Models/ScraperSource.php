<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Source de scraping configurable (agenda/cinéma), remplace t_agenda_scrapping. */
class ScraperSource extends Model
{
    protected $fillable = [
        'name', 'type', 'driver_class', 'config', 'is_active', 'last_run_at', 'last_status',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(ScraperRun::class, 'source_id');
    }
}
