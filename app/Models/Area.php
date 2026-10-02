<?php

namespace App\Models;

use App\Services\Migration\LegacyCleaner;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Lieu physique où se déroulent des événements (remplace t_areas). */
class Area extends Model
{
    use HasSlug;

    protected $fillable = [
        'name', 'slug', 'address', 'city', 'postal_code', 'lat', 'lng', 'phone', 'website', 'legacy_id',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug');
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (02/10/2026, même bug que sur
     * Listing::cleanAddress() — voir son docblock) : 24 `areas.address`
     * legacy contiennent des `<br>` (adresse multi-lignes), affichés
     * littéralement en texte visible sur la fiche détail d'un événement via
     * `Event::venueDisplayAddress()` (repli sur l'Area quand l'événement n'a
     * pas sa propre adresse). `address` reste inchangé en base.
     */
    protected function cleanAddress(): Attribute
    {
        return Attribute::get(fn () => LegacyCleaner::stripHtml($this->address));
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
