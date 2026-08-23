<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScreeningType extends Model
{
    protected $fillable = ['name', 'legacy_id'];

    public function screenings(): BelongsToMany
    {
        return $this->belongsToMany(Screening::class, 'screening_screening_type');
    }
}
