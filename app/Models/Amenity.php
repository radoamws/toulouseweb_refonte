<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Pictogramme/équipement affichable sur une fiche annuaire (remplace t_icone). */
class Amenity extends Model
{
    use ResolvesImageUrl;

    protected $fillable = ['name', 'icon', 'legacy_id'];

    protected function iconUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->icon));
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_amenity');
    }
}
