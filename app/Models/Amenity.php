<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Pictogramme/équipement affichable sur une fiche annuaire (remplace t_icone). */
class Amenity extends Model
{
    protected $fillable = ['name', 'icon', 'legacy_id'];

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_amenity');
    }
}
