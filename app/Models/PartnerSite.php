<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Site partenaire affiché en page contact (remplace t_contact_sites). */
class PartnerSite extends Model
{
    use ResolvesImageUrl;

    protected $fillable = ['name', 'url', 'logo', 'category', 'order', 'legacy_id'];

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->logo));
    }
}
